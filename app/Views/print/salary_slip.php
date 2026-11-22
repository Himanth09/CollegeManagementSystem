<?php
/** @var array $sp @var array $earnings @var array $deductions @var array $college @var callable $e @var callable $money @var callable $date */
$monthLabel = date('F Y', strtotime($sp['pay_month']));
$eKeys = array_keys($earnings);
$dKeys = array_keys($deductions);
$rows = max(count($eKeys), count($dKeys));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Salary Slip <?= $e($sp['employee_id']) ?> <?= $e($monthLabel) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI", Arial, sans-serif; color: #1e293b; background: #f1f5f9; margin: 0; padding: 24px; }
  .sheet { max-width: 820px; margin: 0 auto; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 32px; }
  .head { text-align: center; border-bottom: 3px solid #1d4ed8; padding-bottom: 14px; }
  .head h1 { margin: 0; font-size: 22px; color: #1e3a8a; }
  .head p { margin: 3px 0; font-size: 13px; color: #475569; }
  .head h2 { margin: 12px 0 0; font-size: 16px; letter-spacing: 1px; }
  .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 32px; margin: 20px 0; font-size: 14px; }
  .meta span { color: #64748b; display: inline-block; min-width: 130px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { border: 1px solid #e2e8f0; padding: 9px 12px; }
  th { background: #eff6ff; color: #1e3a8a; text-align: left; }
  .r { text-align: right; font-variant-numeric: tabular-nums; }
  .total td { font-weight: 700; background: #f8fafc; }
  .net { margin-top: 16px; padding: 14px 16px; background: #eff6ff; border-left: 4px solid #1d4ed8; font-size: 15px; }
  .net strong { font-size: 18px; color: #1e3a8a; }
  .foot { display: flex; justify-content: space-between; margin-top: 48px; font-size: 13px; color: #475569; }
  .sign { border-top: 1px solid #94a3b8; padding-top: 6px; min-width: 200px; text-align: center; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
  .paid { background: #dcfce7; color: #166534; } .pending { background: #fef3c7; color: #92400e; }
  .note { font-size: 11px; color: #94a3b8; margin-top: 24px; text-align: center; }
  .actions { text-align: center; margin-top: 20px; }
  .actions button { background: #1d4ed8; color: #fff; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 14px; cursor: pointer; }
  @media print { body { background: #fff; padding: 0; } .sheet { border: 0; } .actions { display: none; } }
  @media (max-width: 600px) { .meta { grid-template-columns: 1fr; } .sheet { padding: 18px; } }
</style>
</head>
<body>
<div class="sheet">
  <div class="head">
    <h1><?= $e($college['name']) ?></h1>
    <p><?= $e($college['address']) ?></p>
    <h2>SALARY SLIP &mdash; <?= $e(strtoupper($monthLabel)) ?></h2>
  </div>

  <div class="meta">
    <div><span>Employee Name:</span> <strong><?= $e($sp['full_name']) ?></strong></div>
    <div><span>Employee ID:</span> <?= $e($sp['employee_id']) ?></div>
    <div><span>Designation:</span> <?= $e($sp['designation']) ?></div>
    <div><span>Department:</span> <?= $e($sp['department_name'] ?: '-') ?></div>
    <div><span>Date of Joining:</span> <?= $date($sp['date_of_joining']) ?></div>
    <div><span>Slip No:</span> <?= $e($sp['slip_no'] ?: '-') ?></div>
    <div><span>Status:</span> <span class="badge <?= $sp['status'] === 'paid' ? 'paid' : 'pending' ?>"><?= $e(ucfirst($sp['status'])) ?></span></div>
    <div><span>Paid On:</span> <?= $date($sp['paid_date']) ?> <?= $sp['payment_mode'] ? '(' . $e($sp['payment_mode']) . ')' : '' ?></div>
  </div>

  <table>
    <thead><tr><th>Earnings</th><th class="r">Amount</th><th>Deductions</th><th class="r">Amount</th></tr></thead>
    <tbody>
    <?php for ($i = 0; $i < $rows; $i++): $ek = $eKeys[$i] ?? null; $dk = $dKeys[$i] ?? null; ?>
      <tr>
        <td><?= $ek ? $e($earnings[$ek]) : '' ?></td>
        <td class="r"><?= $ek ? $money($sp[$ek]) : '' ?></td>
        <td><?= $dk ? $e($deductions[$dk]) : '' ?></td>
        <td class="r"><?= $dk ? $money($sp[$dk]) : '' ?></td>
      </tr>
    <?php endfor; ?>
      <tr class="total">
        <td>Gross Salary</td><td class="r"><?= $money($sp['gross_salary']) ?></td>
        <td>Total Deductions</td><td class="r"><?= $money($sp['total_deductions']) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="net">Net Salary: <strong><?= $money($sp['net_salary']) ?></strong><br><em><?= $e($sp['net_words']) ?></em></div>

  <div class="foot">
    <div>Reference: <?= $e($sp['transaction_ref'] ?: '-') ?><br>Printed: <?= $e(date('d M Y, h:i A')) ?></div>
    <div class="sign">Authorised Signatory</div>
  </div>
  <p class="note">This is a confidential, computer-generated document.</p>
</div>
<div class="actions"><button onclick="window.print()">Print Salary Slip</button></div>
</body>
</html>
