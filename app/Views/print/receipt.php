<?php
/** @var array $p @var array $college @var callable $e @var callable $money @var callable $date */
$cancelled = $p['status'] === 'cancelled';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fee Receipt <?= $e($p['receipt_no']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI", Arial, sans-serif; color: #1e293b; background: #f1f5f9; margin: 0; padding: 24px; }
  .sheet { max-width: 780px; margin: 0 auto; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 32px; position: relative; overflow: hidden; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1d4ed8; padding-bottom: 16px; }
  .head h1 { margin: 0 0 4px; font-size: 22px; color: #1e3a8a; }
  .head p { margin: 2px 0; font-size: 13px; color: #475569; }
  .tag { background: #1d4ed8; color: #fff; padding: 6px 14px; border-radius: 4px; font-weight: 600; letter-spacing: .5px; white-space: nowrap; }
  .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 32px; margin: 20px 0; font-size: 14px; }
  .meta div span { color: #64748b; display: inline-block; min-width: 130px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; margin-top: 8px; }
  th, td { border: 1px solid #e2e8f0; padding: 9px 12px; text-align: left; }
  th { background: #eff6ff; color: #1e3a8a; }
  td.r, th.r { text-align: right; font-variant-numeric: tabular-nums; }
  .total td { font-weight: 700; background: #f8fafc; }
  .words { margin: 14px 0; font-size: 14px; font-style: italic; }
  .foot { display: flex; justify-content: space-between; margin-top: 48px; font-size: 13px; color: #475569; }
  .sign { border-top: 1px solid #94a3b8; padding-top: 6px; min-width: 200px; text-align: center; }
  .note { font-size: 11px; color: #94a3b8; margin-top: 24px; text-align: center; }
  .stamp { position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%) rotate(-20deg); font-size: 90px; font-weight: 800; color: rgba(220, 38, 38, .15); border: 8px solid rgba(220, 38, 38, .15); padding: 0 24px; pointer-events: none; }
  .actions { text-align: center; margin: 20px 0 0; }
  .actions button { background: #1d4ed8; color: #fff; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 14px; cursor: pointer; }
  @media print { body { background: #fff; padding: 0; } .sheet { border: 0; } .actions { display: none; } }
  @media (max-width: 600px) { .meta { grid-template-columns: 1fr; } .head { flex-direction: column; gap: 10px; } .sheet { padding: 18px; } }
</style>
</head>
<body>
<div class="sheet">
  <?php if ($cancelled): ?><div class="stamp">CANCELLED</div><?php endif; ?>
  <div class="head">
    <div>
      <h1><?= $e($college['name']) ?></h1>
      <p><?= $e($college['address']) ?></p>
      <p>Phone: <?= $e($college['phone']) ?> &nbsp;|&nbsp; Email: <?= $e($college['email']) ?></p>
    </div>
    <div class="tag">FEE RECEIPT</div>
  </div>

  <div class="meta">
    <div><span>Receipt No:</span> <strong><?= $e($p['receipt_no']) ?></strong></div>
    <div><span>Payment Date:</span> <?= $date($p['payment_date']) ?></div>
    <div><span>Student Name:</span> <strong><?= $e($p['student_name']) ?></strong></div>
    <div><span>Admission No:</span> <?= $e($p['admission_no']) ?></div>
    <div><span>Father's Name:</span> <?= $e($p['father_name'] ?: '-') ?></div>
    <div><span>Course:</span> <?= $e($p['course_name']) ?></div>
    <div><span>Department:</span> <?= $e($p['department_name']) ?></div>
    <div><span>Academic Year:</span> <?= $e($p['academic_year']) ?></div>
  </div>

  <table>
    <thead><tr><th>Description</th><th>Payment Mode</th><th>Reference</th><th class="r">Amount</th></tr></thead>
    <tbody>
      <tr>
        <td><?= $e($p['assignment_title']) ?><?= $p['remarks'] ? '<br><small>' . $e($p['remarks']) . '</small>' : '' ?></td>
        <td><?= $e($p['payment_mode']) ?></td>
        <td><?= $e($p['transaction_ref'] ?: '-') ?></td>
        <td class="r"><?= $money($p['amount']) ?></td>
      </tr>
      <tr class="total"><td colspan="3">Amount Received</td><td class="r"><?= $money($p['amount']) ?></td></tr>
    </tbody>
  </table>
  <p class="words">Amount in words: <?= $e($p['amount_words']) ?></p>

  <table>
    <tbody>
      <tr><td>Total Payable for this fee</td><td class="r"><?= $money($p['total_payable']) ?></td></tr>
      <tr><td>Total Paid to Date (incl. this receipt)</td><td class="r"><?= $money($p['paid_to_date']) ?></td></tr>
      <tr class="total"><td>Outstanding Balance after this payment</td><td class="r"><?= $money($p['balance_after']) ?></td></tr>
    </tbody>
  </table>

  <div class="foot">
    <div>Received by: <?= $e($p['recorded_by_name'] ?: '-') ?><br>Printed: <?= $e(date('d M Y, h:i A')) ?></div>
    <div class="sign">Authorised Signatory</div>
  </div>
  <?php if ($cancelled): ?><p class="note" style="color:#dc2626">This receipt was cancelled: <?= $e($p['review_note']) ?></p><?php endif; ?>
  <p class="note">This is a computer-generated receipt.</p>
</div>
<div class="actions"><button onclick="window.print()">Print Receipt</button></div>
</body>
</html>
