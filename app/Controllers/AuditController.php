<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ListQuery;
use App\Core\Request;
use App\Core\Response;

final class AuditController
{
    public static function index(): never
    {
        Auth::requirePermission('audit.view');
        $q = new ListQuery();
        $q->search(Request::queryStr('q'), ['l.action', 'l.entity_type', 'l.entity_id', 'u.username']);
        $q->when(Request::queryStr('entity_type'), 'l.entity_type = ?');
        $q->when(Request::queryInt('user_id'), 'l.user_id = ?');
        $q->when(Request::queryDate('from'), 'l.created_at >= ?');
        if ($to = Request::queryDate('to')) {
            $q->add('l.created_at < DATE_ADD(?, INTERVAL 1 DAY)', $to);
        }
        if (Request::queryStr('financial') === '1') {
            $q->add("(l.entity_type IN ('fee_payment','fee_assignment','fee_structure','salary_structure','salary_payment','payroll'))");
        }
        $result = $q->paginate(
            'l.id, l.action, l.entity_type, l.entity_id, l.old_values, l.new_values, l.ip_address, l.created_at,
             u.username, u.full_name',
            'FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id',
            ListQuery::orderBy(['created_at' => 'l.id', 'action' => 'l.action', 'entity_type' => 'l.entity_type'], 'created_at', 'desc'),
            25
        );
        foreach ($result['data'] as &$r) {
            $r['old_values'] = $r['old_values'] ? json_decode($r['old_values'], true) : null;
            $r['new_values'] = $r['new_values'] ? json_decode($r['new_values'], true) : null;
        }
        Response::ok($result);
    }
}
