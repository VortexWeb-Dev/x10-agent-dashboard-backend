<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . "/../services/CacheService.php";
require_once __DIR__ . "/../services/ResponseService.php";
require_once __DIR__ . "/BitrixController.php";

class RankingController extends BitrixController
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

        if (!$id || !is_numeric($id)) {
            $this->response->sendError(400, "Parameter 'id' is required and must be numeric");
            return;
        }

        $cacheKey = "ranking_" . $id;
        $cached = $this->cache->get($cacheKey);

        if ($cached !== false) {
            $this->response->sendSuccess(200, $cached);
            return;
        }

        $allDeals = $this->getDeals(['CLOSED' => 'Y', '>OPPORTUNITY' => 0], ['ID', 'CLOSEDATE', 'OPPORTUNITY', 'ASSIGNED_BY_ID']);
        $deals = array_filter($allDeals, function ($deal) use ($id) {
            return $deal['ASSIGNED_BY_ID'] == $id;
        });

        if (!$deals) {
            $this->response->sendError(404, "No deals found for agent ID: $id");
            return;
        }

        $ranking = $this->calculateRankings($allDeals, $deals, (int)$id);

        $this->cache->set($cacheKey, $ranking);
        $this->response->sendSuccess(200, $ranking);
    }

    private function calculateRankings(array $allDeals, array $deals, int $id): array
    {
        $currentYear = (int)date('Y');
        $ranking = [];

        for ($year = $currentYear - 2; $year <= $currentYear; $year++) {
            $ranking[$year] = [
                'months' => array_fill(0, 12, ['grossCommission' => 0]),
                'quarters' => array_fill(0, 4, ['grossCommission' => 0]),
                'year' => ['grossCommission' => 0]
            ];
        }

        foreach ($deals as $deal) {
            $closeDate = new DateTime($deal['CLOSEDATE']);
            $dealYear = (int)$closeDate->format('Y');
            $dealMonth = (int)$closeDate->format('n') - 1;
            $dealQuarter = floor($dealMonth / 3);

            if ($dealYear < $currentYear - 2 || $dealYear > $currentYear) {
                continue;
            }

            $commission = isset($deal['OPPORTUNITY']) ? (float)$deal['OPPORTUNITY'] * 0.03 : 0;

            $ranking[$dealYear]['months'][$dealMonth]['grossCommission'] += $commission;
            $ranking[$dealYear]['quarters'][$dealQuarter]['grossCommission'] += $commission;
            $ranking[$dealYear]['year']['grossCommission'] += $commission;
        }

        foreach ($ranking as $year => &$yearData) {
            $formattedMonths = [];
            for ($m = 0; $m < 12; $m++) {
                $month = date('M', mktime(0, 0, 0, $m + 1, 1));
                $formattedMonths[] = [
                    'month' => $month,
                    'rank' => 0,
                    'grossCommission' => $yearData['months'][$m]['grossCommission'] ?? 0
                ];
            }
            $yearData['months'] = $formattedMonths;

            $formattedQuarters = [];
            $quarterNames = ['Q1', 'Q2', 'Q3', 'Q4'];
            for ($q = 0; $q < 4; $q++) {
                $formattedQuarters[] = [
                    'quarter' => $quarterNames[$q],
                    'rank' => 0,
                    'grossCommission' => $yearData['quarters'][$q]['grossCommission'] ?? 0
                ];
            }
            $yearData['quarters'] = $formattedQuarters;

            $yearData['year'] = [
                'rank' => 0,
                'grossCommission' => $yearData['year']['grossCommission']
            ];
        }

        $this->calculateRankingsForPeriod($allDeals, $ranking, $id);

        return $ranking;
    }

    private function calculateRankingsForPeriod(array $allDeals, array &$ranking, int $targetId): void
    {
        $currentYear = (int)date('Y');
        $agents = $this->getAllUsers(['ACTIVE' => 'Y'], ['ID']);

        $dealsByAgent = [];
        foreach ($allDeals as $deal) {
            $assignedId = $deal['ASSIGNED_BY_ID'];
            if (!isset($dealsByAgent[$assignedId])) {
                $dealsByAgent[$assignedId] = [];
            }
            $dealsByAgent[$assignedId][] = $deal;
        }

        for ($year = $currentYear - 2; $year <= $currentYear; $year++) {
            $monthlyScores = array_fill(0, 12, []);
            $quarterlyScores = array_fill(0, 4, []);
            $yearlyScores = [];

            foreach ($agents as $agent) {
                $agentId = $agent['ID'];
                $agentDeals = $dealsByAgent[$agentId] ?? [];

                $monthlyTotals = array_fill(0, 12, 0);
                $quarterlyTotals = array_fill(0, 4, 0);
                $yearlyTotal = 0;

                foreach ($agentDeals as $deal) {
                    $closeDate = new DateTime($deal['CLOSEDATE']);
                    $dealYear = (int)$closeDate->format('Y');

                    if ($dealYear !== $year) continue;

                    $commission = isset($deal['OPPORTUNITY']) ? (float)$deal['OPPORTUNITY'] * 0.03 : 0;

                    $dealMonth = (int)$closeDate->format('n') - 1;
                    $dealQuarter = floor($dealMonth / 3);

                    $monthlyTotals[$dealMonth] += $commission;
                    $quarterlyTotals[$dealQuarter] += $commission;
                    $yearlyTotal += $commission;
                }

                for ($m = 0; $m < 12; $m++) {
                    if ($monthlyTotals[$m] > 0) {
                        $monthlyScores[$m][] = ['id' => $agentId, 'commission' => $monthlyTotals[$m]];
                    }
                }

                for ($q = 0; $q < 4; $q++) {
                    if ($quarterlyTotals[$q] > 0) {
                        $quarterlyScores[$q][] = ['id' => $agentId, 'commission' => $quarterlyTotals[$q]];
                    }
                }

                if ($yearlyTotal > 0) {
                    $yearlyScores[] = ['id' => $agentId, 'commission' => $yearlyTotal];
                }
            }

            for ($m = 0; $m < 12; $m++) {
                if (!empty($monthlyScores[$m])) {
                    usort($monthlyScores[$m], fn($a, $b) => $b['commission'] <=> $a['commission']);
                    $ranking[$year]['months'][$m]['rank'] = $this->findRank($monthlyScores[$m], $targetId);
                }
            }

            for ($q = 0; $q < 4; $q++) {
                if (!empty($quarterlyScores[$q])) {
                    usort($quarterlyScores[$q], fn($a, $b) => $b['commission'] <=> $a['commission']);
                    $ranking[$year]['quarters'][$q]['rank'] = $this->findRank($quarterlyScores[$q], $targetId);
                }
            }

            if (!empty($yearlyScores)) {
                usort($yearlyScores, fn($a, $b) => $b['commission'] <=> $a['commission']);
                $ranking[$year]['year']['rank'] = $this->findRank($yearlyScores, $targetId);
            }
        }
    }

    private function findRank(array $scores, int $targetId): int
    {
        foreach ($scores as $rank => $agent) {
            if ($agent['id'] == $targetId) {
                return $rank + 1;
            }
        }
        return 0;
    }
}
