<?php

require_once __DIR__ . "/../crest/crest.php";

class BitrixController
{
    private $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/config.php';
    }

    public function getDeals(array $filter = [], array $select = []): ?array
    {
        $allDeals = [];
        $start = 0;

        do {
            $params = [
                'filter' => $filter,
                'select' => $select,
                'start' => $start
            ];

            $response = CRest::call('crm.deal.list', $params);

            if (!isset($response['result'])) {
                break;
            }

            $allDeals = array_merge($allDeals, $response['result']);
            $start = $response['next'] ?? null;
        } while ($start !== null);

        return $allDeals ?: null;
    }

    public function getLeads(array $filter = [], array $select = []): ?array
    {
        $allLeads = [];
        $start = 0;

        do {
            $params = [
                'filter' => $filter,
                'select' => $select,
                'start' => $start
            ];

            $response = CRest::call('crm.lead.list', $params);

            if (!isset($response['result'])) {
                break;
            }

            $allLeads = array_merge($allLeads, $response['result']);
            $start = $response['next'] ?? null;
        } while ($start !== null);

        return $allLeads ?: [];
    }

    public function getUser(int $id, array $select = []): ?array
    {
        $result = CRest::call('user.get', ['ID' => $id, 'select' => $select]);
        return $result['result'][0] ?? null;
    }

    public function searchUsers(array $filter = []): array
    {
        $result = CRest::call('user.get', ['filter' => $filter]);
        return $result['result'] ?? [];
    }

    public function getAllUsers(array $filter = ['ACTIVE' => 'Y'], array $select = ['ID']): array
    {
        $allUsers = [];
        $start = 0;

        do {
            $response = CRest::call('user.get', [
                'order' => ['ID' => 'ASC'],
                'filter' => $filter,
                'select' => $select,
                'start' => $start
            ]);

            $users = $response['result'] ?? [];
            $allUsers = array_merge($allUsers, $users);

            $start = isset($response['next']) ? $response['next'] : null;
        } while ($start !== null);

        return $allUsers;
    }

    public function getAllUserAds(array $filter = [], array $select = []): array
    {
        $allItems = [];
        $start = 0;

        do {
            $response = CRest::call('crm.item.list', [
                'entityTypeId' => $this->config['LISTINGS_ENTITY_TYPE_ID'],
                'filter' => $filter,
                'select' => $select,
                'start' => $start
            ]);

            $items = $response['result']['items'] ?? [];
            $allItems = array_merge($allItems, $items);

            $start = isset($response['next']) ? $response['next'] : null;
        } while ($start !== null);

        return $allItems;
    }
}
