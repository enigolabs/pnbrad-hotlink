<?php

/**
 * Agent ↔ router scoping for PHPNuxBill / PNBrad.
 *
 * Agents (and Sales under an Agent via tbl_users.root) only see customers /
 * routers assigned through tbl_agent_routers.
 *
 * Customer visibility rule:
 *   A customer is in scope if they have at least one tbl_user_recharges row
 *   whose `routers` column matches an allowed router **name**.
 *   Customers with no recharge yet are hidden from Agents/Sales.
 * SuperAdmin / Admin: unrestricted.
 */

class AgentScope
{
    private static $ensured = false;
    private static $cacheIds = [];
    private static $cacheNames = [];

    public static function ensureTable()
    {
        if (self::$ensured) {
            return;
        }
        try {
            ORM::raw_execute("CREATE TABLE IF NOT EXISTS `tbl_agent_routers` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `user_id` INT UNSIGNED NOT NULL,
              `router_id` INT UNSIGNED NOT NULL,
              UNIQUE KEY `uq_agent_router` (`user_id`, `router_id`),
              KEY `idx_router` (`router_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::$ensured = true;
        } catch (Exception $e) {
            // leave $ensured false so a later call can retry
        }
    }

    /**
     * True when this admin account must be router-scoped.
     */
    public static function isScoped($admin)
    {
        if (!$admin) {
            return false;
        }
        $type = is_array($admin) ? ($admin['user_type'] ?? '') : ($admin->user_type ?? '');
        return in_array($type, ['Agent', 'Sales'], true);
    }

    /**
     * Resolve the Agent user id that owns the router mapping.
     * Sales inherit via `root` (parent Agent).
     */
    public static function scopeOwnerId($admin)
    {
        if (!$admin) {
            return 0;
        }
        $type = is_array($admin) ? ($admin['user_type'] ?? '') : ($admin->user_type ?? '');
        $id = (int)(is_array($admin) ? ($admin['id'] ?? 0) : ($admin->id ?? 0));
        if ($type === 'Agent') {
            return $id;
        }
        if ($type === 'Sales') {
            $root = (int)(is_array($admin) ? ($admin['root'] ?? 0) : ($admin->root ?? 0));
            return $root > 0 ? $root : 0;
        }
        return 0;
    }

    /**
     * Assigned router IDs for this admin (empty = no routers → empty customer list).
     */
    public static function routerIds($admin)
    {
        if (!self::isScoped($admin)) {
            return null; // unrestricted
        }
        $owner = self::scopeOwnerId($admin);
        if ($owner <= 0) {
            return [];
        }
        if (isset(self::$cacheIds[$owner])) {
            return self::$cacheIds[$owner];
        }
        self::ensureTable();
        $rows = ORM::for_table('tbl_agent_routers')
            ->where('user_id', $owner)
            ->find_array();
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int)$r['router_id'];
        }
        self::$cacheIds[$owner] = $ids;
        return $ids;
    }

    /**
     * Assigned router names (tbl_routers.name) — matches tbl_user_recharges.routers / plans.routers.
     */
    public static function routerNames($admin)
    {
        if (!self::isScoped($admin)) {
            return null;
        }
        $owner = self::scopeOwnerId($admin);
        if ($owner <= 0) {
            return [];
        }
        if (isset(self::$cacheNames[$owner])) {
            return self::$cacheNames[$owner];
        }
        $ids = self::routerIds($admin);
        if (empty($ids)) {
            self::$cacheNames[$owner] = [];
            return [];
        }
        $routers = ORM::for_table('tbl_routers')->where_in('id', $ids)->find_array();
        $names = [];
        foreach ($routers as $r) {
            if (!empty($r['name'])) {
                $names[] = $r['name'];
            }
        }
        self::$cacheNames[$owner] = $names;
        return $names;
    }

    /**
     * Customer IDs visible to this admin, or null if unrestricted.
     * Empty array = scoped but no matching customers.
     */
    public static function customerIds($admin)
    {
        $names = self::routerNames($admin);
        if ($names === null) {
            return null;
        }
        if (empty($names)) {
            return [];
        }
        $rows = ORM::for_table('tbl_user_recharges')
            ->select('customer_id')
            ->where_in('routers', $names)
            ->distinct()
            ->find_array();
        $ids = [];
        foreach ($rows as $r) {
            $cid = (int)$r['customer_id'];
            if ($cid > 0) {
                $ids[] = $cid;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Restrict an ORM query on tbl_customers (or joined) to in-scope customers.
     * Uses where_in on tbl_customers.id when possible.
     */
    public static function filterCustomersQuery($query, $admin, $idColumn = 'tbl_customers.id')
    {
        $ids = self::customerIds($admin);
        if ($ids === null) {
            return $query;
        }
        if (empty($ids)) {
            // Force empty result without breaking ORM
            $query->where_raw('1=0');
            return $query;
        }
        // Idiorm where_in needs the column name; for plain tbl_customers queries use 'id'
        if ($idColumn === 'tbl_customers.id' || $idColumn === 'id') {
            $query->where_in('id', $ids);
        } else {
            $query->where_in($idColumn, $ids);
        }
        return $query;
    }

    /**
     * Restrict query on tables that have a `routers` name column (recharges, transactions, plans filters).
     */
    public static function filterByRouterNames($query, $admin, $column = 'routers')
    {
        $names = self::routerNames($admin);
        if ($names === null) {
            return $query;
        }
        if (empty($names)) {
            $query->where_raw('1=0');
            return $query;
        }
        $query->where_in($column, $names);
        return $query;
    }

    /**
     * Restrict tbl_routers query to assigned IDs.
     */
    public static function filterRoutersQuery($query, $admin)
    {
        $ids = self::routerIds($admin);
        if ($ids === null) {
            return $query;
        }
        if (empty($ids)) {
            $query->where_raw('1=0');
            return $query;
        }
        $query->where_in('id', $ids);
        return $query;
    }

    public static function canAccessCustomer($admin, $customerId)
    {
        $ids = self::customerIds($admin);
        if ($ids === null) {
            return true;
        }
        return in_array((int)$customerId, $ids, true);
    }

    public static function canAccessRouter($admin, $routerId)
    {
        $ids = self::routerIds($admin);
        if ($ids === null) {
            return true;
        }
        return in_array((int)$routerId, $ids, true);
    }

    public static function canAccessRouterName($admin, $routerName)
    {
        $names = self::routerNames($admin);
        if ($names === null) {
            return true;
        }
        return in_array((string)$routerName, $names, true);
    }

    /**
     * Deny with redirect if customer is out of scope.
     */
    public static function assertCustomerAccess($admin, $customerId, $redirect = 'customers')
    {
        if (!self::canAccessCustomer($admin, $customerId)) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', $redirect);
        }
    }

    /**
     * Replace agent↔router mappings. Only meaningful for Agent user_type.
     * $routerIds: array of int router ids from POST.
     */
    public static function saveMappings($userId, $routerIds)
    {
        self::ensureTable();
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        ORM::for_table('tbl_agent_routers')->where('user_id', $userId)->delete_many();
        if (!is_array($routerIds)) {
            return;
        }
        $seen = [];
        foreach ($routerIds as $rid) {
            $rid = (int)$rid;
            if ($rid <= 0 || isset($seen[$rid])) {
                continue;
            }
            // only persist existing routers
            $exists = ORM::for_table('tbl_routers')->find_one($rid);
            if (!$exists) {
                continue;
            }
            $seen[$rid] = true;
            $row = ORM::for_table('tbl_agent_routers')->create();
            $row->user_id = $userId;
            $row->router_id = $rid;
            $row->save();
        }
        unset(self::$cacheIds[$userId], self::$cacheNames[$userId]);
    }

    /**
     * Router IDs currently mapped to a given agent user id (for edit form).
     */
    public static function mappedRouterIdsForUser($userId)
    {
        self::ensureTable();
        $rows = ORM::for_table('tbl_agent_routers')
            ->where('user_id', (int)$userId)
            ->find_array();
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int)$r['router_id'];
        }
        return $ids;
    }

    /**
     * Enabled routers for checklist UI.
     */
    public static function enabledRouters()
    {
        return ORM::for_table('tbl_routers')
            ->where('enabled', '1')
            ->order_by_asc('name')
            ->find_many();
    }
}
