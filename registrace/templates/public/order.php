<?php
use Bkl\Util;

$statusLabel = ['ceka_na_platbu' => 'čeká na platbu', 'potvrzena' => 'potvrzená', 'zrusena' => 'zrušená', 'expirovana' => 'zrušená (nezaplaceno)', 'ke_kontrole' => 'v řešení u pořadatele', 'rozpracovana' => 'rozpracovaná'];
$payLabel = ['nezaplaceno' => 'nezaplaceno', 'castecne' => 'zaplaceno částečně', 'zaplaceno' => 'zaplaceno', 'preplaceno' => 'zaplaceno', 'vraceno' => 'vráceno', 'ke_kontrole' => 'v řešení'];
?>
<article class="order">
  <h1><?= $e(Util::tr($ev['name'])) ?></h1>
  <p class="meta"><?= $e(Util::fmtDateTime($ev['starts_at'])) ?><?= $ev['venue'] ? ' · ' . $e($ev['venue']) : '' ?></p>

  <?php if ($fresh): ?>
    <div class="ok-box" role="status"><b>Registrace byla přijata.</b> Potvrzení s platebními údaji jsme poslali na <b><?= $e($o['contact_email']) ?></b>. Pokud e-mail nedorazí, podívejte se do složky spam/hromadné. Tuto stránku si můžete uložit do záložek.</div>
  <?php endif; ?>

  <?php if ($unpaid && $qr): $rest = (int) $o['amount_total'] - (int) $o['amount_paid']; ?>
  <section class="card pay">
    <h2>Platba</h2>
    <div class="pay-grid">
      <div class="qr" aria-label="QR Platba"><?= $qr ?></div>
      <dl>
        <dt>Číslo účtu</dt><dd><b><?= $e($o['pay_account']) ?></b></dd>
        <dt>Částka</dt><dd><b><?= $e($money($rest)) ?></b></dd>
        <dt>Variabilní symbol</dt><dd><b><?= $e($o['vs']) ?></b></dd>
        <dt>Splatnost</dt><dd><?= $e(Util::fmtDate($o['due_date'])) ?></dd>
      </dl>
    </div>
    <p class="hint">Naskenujte QR kód v aplikaci banky, nebo zadejte platbu ručně – <b>vždy s variabilním symbolem</b>. Registrace se potvrdí automaticky po připsání platby (obvykle do druhého pracovního dne).</p>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>Přihlášení závodníci</h2>
    <table class="tbl">
      <thead><tr><th>Závodník</th><th>Trať</th><th>Stav</th><th class="r">Cena</th></tr></thead>
      <tbody>
      <?php foreach ($regs as $r): ?>
        <tr><td><b><?= $e($r['first_name'] . ' ' . $r['last_name']) ?></b><br><small><?= $e(substr((string) $r['birth_date'], 0, 4)) ?>, <?= $e($r['club']) ?></small></td>
          <td><?= $e(Util::tr($r['race_name'])) ?><?= $r['bib'] ? '<br><small>číslo ' . (int) $r['bib'] . '</small>' : '' ?></td>
          <td><span class="badge s-<?= $e($r['status']) ?>"><?= $e($statusLabel[$r['status']] ?? $r['status']) ?></span></td>
          <td class="r"><?= $e($money((int) $r['price_final'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if ((int) $o['amount_charity'] > 0): ?><tr><td colspan="3">Dobrovolný příspěvek<?= $ev['charity'] ? ' – ' . $e(Util::tr($ev['charity']['purpose'])) : '' ?></td><td class="r"><?= $e($money((int) $o['amount_charity'])) ?></td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr><td colspan="3">Celkem · <?= $e($payLabel[$o['payment_status']] ?? $o['payment_status']) ?><?= (int) $o['amount_paid'] > 0 ? ' (přijato ' . $e($money((int) $o['amount_paid'])) . ')' : '' ?></td><td class="r"><b><?= $e($money((int) $o['amount_total'])) ?></b></td></tr></tfoot>
    </table>
    <p class="hint">Variabilní symbol přihlášky: <?= $e($o['vs']) ?></p>
  </section>

  <section class="card">
    <h2>QR kód pro prezenci</h2>
    <p class="hint">Na místě ho ukažte u registrace – urychlí vydání startovního čísla.</p>
    <div class="checkins">
      <?php foreach ($regs as $r): if (!in_array($r['status'], ['ceka_na_platbu', 'potvrzena', 'ke_kontrole'], true)) { continue; } ?>
        <figure><?= $checkins[(int) $r['id']] ?><figcaption><?= $e($r['first_name'] . ' ' . $r['last_name']) ?></figcaption></figure>
      <?php endforeach; ?>
    </div>
  </section>

  <p class="hint">Potřebujete něco změnit? Napište pořadateli<?= $ev['contact_email'] ? ' na <a href="mailto:' . $e($ev['contact_email']) . '">' . $e($ev['contact_email']) . '</a>' : '' ?> a uveďte variabilní symbol <?= $e($o['vs']) ?>.</p>
  <p><a href="<?= $e($url($ev['slug'] . ($embed ? '?embed=1' : ''))) ?>">← Zpět na registraci</a></p>
</article>
