<?php

require_once __DIR__ . "/../services/CacheService.php";
require_once __DIR__ . "/../services/ResponseService.php";
require_once __DIR__ . "/BitrixController.php";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

class PerformanceController extends BitrixController
{
    private CacheService $cache;
    private ResponseService $response;

    public function __construct()
    {
        parent::__construct();
        $this->cache = new CacheService(300);
        $this->response = new ResponseService();
    }

    public function processRequest(string $method, ?string $id): void
    {
        if ($method !== 'GET') {
            $this->response->sendError(405, "Method Not Allowed");
            return;
        }

        if (!$id) {
            $this->response->sendError(400, "Missing required parameter 'id'");
            return;
        }

        if (!is_numeric($id)) {
            $this->response->sendError(400, "Parameter 'id' must be a number");
            return;
        }

        $isYearly = isset($_GET['yearly']) && $_GET['yearly'] === 'true';

        if ($isYearly) {
            $year = date('Y');
            $currentMonth = (int)date('n');

            // Get user info just once
            $user = $this->getUserById($id, [
                'NAME',
                'LAST_NAME',
                'WORK_POSITION',
                'PERSONAL_PHOTO',
                'EMAIL',
                'UF_SKYPE_LINK',
                'UF_ZOOM',
                'UF_XING',
                'UF_LINKEDIN',
                'UF_FACEBOOK',
                'UF_TWITTER',
                'UF_SKYPE'
            ]);

            if (!$user) {
                $this->response->sendError(404, "User not found");
                return;
            }

            $userData = $this->getUserDataArray($user);
            $yearlyPerformance = [];

            for ($month = 1; $month <= $currentMonth; $month++) {
                $key = "performance_month_{$id}_{$year}_{$month}";
                $cached = $this->cache->get($key);

                if ($cached !== false) {
                    $yearlyPerformance = array_merge($yearlyPerformance, $cached);
                    continue;
                }

                $monthData = $this->getMonthlyPerformanceData($id, $month, $year, $user['EMAIL'] ?? '');
                $this->cache->set($key, $monthData);
                $yearlyPerformance = array_merge($yearlyPerformance, $monthData);
            }

            $userData['performance'] = $yearlyPerformance;
            $this->response->sendSuccess(200, $userData);
            return;
        }

        // Default: current month only
        $cacheKey = "performance_" . $id;
        $cached = $this->cache->get($cacheKey);

        if ($cached !== false) {
            $this->response->sendSuccess(200, $cached);
            return;
        }

        $user = $this->getUserById($id, [
            'NAME',
            'LAST_NAME',
            'WORK_POSITION',
            'PERSONAL_PHOTO',
            'EMAIL',
            'UF_SKYPE_LINK',
            'UF_ZOOM',
            'UF_XING',
            'UF_LINKEDIN',
            'UF_FACEBOOK',
            'UF_TWITTER',
            'UF_SKYPE'
        ]);

        if (!$user) {
            $this->response->sendError(404, "User not found");
            return;
        }

        $performanceData = $this->getPerformanceData($id, $user);

        $this->cache->set($cacheKey, $performanceData);
        $this->response->sendSuccess(200, $performanceData);
    }

    private function getUserById(string $id, array $fields = []): ?array
    {
        $user = $this->getUser($id, $fields);
        if (!$user) {
            return null;
        }
        return $user;
    }

    private function getUserDataArray(array $user): array
    {
        return [
            'employee' => trim($user['NAME'] . ' ' . $user['LAST_NAME']) ?? '',
            'role' => $user['WORK_POSITION'] ?? '',
            'employee_photo' => $user['PERSONAL_PHOTO'] ?? '',
            'skype' => $user['UF_SKYPE'] ?? '',
            'skypeChat' => $user['UF_SKYPE_LINK'] ?? '',
            'zoom' => $user['UF_ZOOM'] ?? '',
            'xing' => $user['UF_XING'] ?? '',
            'linkedin' => $user['UF_LINKEDIN'] ?? '',
            'facebook' => $user['UF_FACEBOOK'] ?? '',
            'twitter' => $user['UF_TWITTER'] ?? '',
        ];
    }

    private function getMonthlyPerformanceData(string $id, int $month, int $year, string $userEmail): array
    {
        $date = DateTime::createFromFormat('!m', $month);
        $monthName = $date->format('F');

        // Build date range for the month
        $startDate = (new DateTime("$year-$month-01"))->format('Y-m-d');
        $endDate = (new DateTime("$year-$month-01"))->modify('last day of this month')->format('Y-m-d');

        // Filter ads based on creation time (Bitrix does not support date filtering on custom fields directly)
        $ads = $this->getAllUserAds(['ufCrm18AgentEmail' => $userEmail], [
            'ufCrm18Status',
            'ufCrm18PfEnable',
            'ufCrm18BayutEnable',
            'ufCrm18DubizzleEnable',
            'ufCrm18WebsiteEnable',
            'ufCrm18Price',
            'createdTime'
        ]);
        $ads = array_filter($ads, function ($ad) use ($startDate, $endDate) {
            if (empty($ad['createdTime'])) return false;
            $created = strtotime($ad['createdTime']);
            return $created >= strtotime($startDate) && $created <= strtotime($endDate);
        });

        // Deals with CLOSEDATE in this month
        $deals = $this->getDeals([
            'ASSIGNED_BY_ID' => $id,
            '>=CLOSEDATE' => $startDate,
            '<=CLOSEDATE' => $endDate
        ], ['ID', 'CLOSEDATE', 'OPPORTUNITY', 'STAGE_ID', 'UF_CRM_1743850215298', 'CLOSED']);

        // Deals without updates for over 14 days
        $dealsWithoutUpdates = array_filter($deals, function ($deal) {
            if (empty($deal['LAST_ACTIVITY_DATE'])) return true;
            return (time() - strtotime($deal['LAST_ACTIVITY_DATE'])) > 14 * 24 * 60 * 60;
        });

        // Ad categorization
        $published = array_filter($ads, fn($ad) => $ad['ufCrm18Status'] === 'PUBLISHED');
        $live = array_filter($ads, fn($ad) => $ad['ufCrm18Status'] === 'LIVE');
        $draft = array_filter($ads, fn($ad) => $ad['ufCrm18Status'] === 'DRAFT');

        $pf = array_filter($published, fn($ad) => $ad['ufCrm18PfEnable'] === 'Y');
        $bayut = array_filter($published, fn($ad) => $ad['ufCrm18BayutEnable'] === 'Y');
        $dubizzle = array_filter($published, fn($ad) => $ad['ufCrm18DubizzleEnable'] === 'Y');
        $website = array_filter($published, fn($ad) => $ad['ufCrm18WebsiteEnable'] === 'Y');

        $worth = array_sum(array_map(
            fn($ad) => (float)$ad['ufCrm18Price'],
            $published
        ));

        // Deal categorization
        $closedDeals = array_filter($deals, fn($deal) => str_starts_with($deal['STAGE_ID'], 'WON') || $deal['CLOSED'] === 'Y');
        $activeDeals = array_filter(
            $deals,
            fn($deal) =>
            in_array($deal['STAGE_ID'], ['NEW', 'PREPARATION', 'IN_PROGRESS', 'FINAL_INVOICE'])
        );
        $unassignedDeals = array_filter(
            $deals,
            fn($deal) =>
            in_array($deal['STAGE_ID'], ['NEW'])
        );
        $meetingsArranged = array_filter($deals, fn($deal) => in_array($deal['STAGE_ID'], ['UC_9QFUT2']));

        $monthlyEarnings = array_sum(array_map(fn($deal) => (float)$deal['OPPORTUNITY'], $closedDeals));
        $grossCommission = array_sum(array_map(fn($deal) => (float)$deal['UF_CRM_1743850215298'], $closedDeals));

        return [
            $monthName => [
                'liveAds' => count($live),
                'totalWorthOfAds' => $worth,
                'publishedAds' => count($published),
                'draftAds' => count($draft),
                'pfAds' => count($pf),
                'bayutAds' => count($bayut),
                'dubizzleAds' => count($dubizzle),
                'websiteAds' => count($website),
                'totalAds' => count($ads),

                'closedDeals' => count($closedDeals),
                'activeDeals' => count($activeDeals),
                'unassignedDeals' => count($unassignedDeals),
                'dealsWithoutUpdates' => count($dealsWithoutUpdates),
                'meetingsArranged' => count($meetingsArranged),
                'monthlyEarnings' => round($monthlyEarnings, 2),
                'grossCommission' => round($grossCommission, 2)
            ]
        ];
    }

    private function getPerformanceData(string $id, array $user = null): array
    {
        $year = date('Y');
        $month = (int)date('n'); // Current month

        if ($user === null) {
            $user = $this->getUserById($id, [
                'NAME',
                'LAST_NAME',
                'WORK_POSITION',
                'PERSONAL_PHOTO',
                'EMAIL',
                'UF_SKYPE_LINK',
                'UF_ZOOM',
                'UF_XING',
                'UF_LINKEDIN',
                'UF_FACEBOOK',
                'UF_TWITTER',
                'UF_SKYPE'
            ]);
        }

        $userData = $this->getUserDataArray($user);
        $monthlyData = $this->getMonthlyPerformanceData($id, $month, $year, $user['EMAIL'] ?? '');
        $userData['performance'] = $monthlyData;

        return $userData;
    }
}
