<?php
use Bkl\Util;

$races = [];
foreach ($ev['races'] as $r) {
    $races[(int) $r['id']] = Util::tr($r['name']);
}
?>
<article class="startlist">
  <h1>Startovní listina</h1>
  <p class="meta"><a href="<?= $e($url($ev['slug'] . ($embed ? '?embed=1' : ''))) ?>"><?= $e(Util::tr($ev['name'])) ?></a> · <?= $e(Util::fmtDateTime($ev['starts_at'])) ?></p>
  <div class="filters">
    <input type="search" id="sl-search" placeholder="Hledat jméno nebo klub…" aria-label="Hledat">
    <select id="sl-race" aria-label="Trať"><option value="">Všechny tratě (<?= count($rows) ?>)</option>
      <?php foreach ($races as $id => $name): ?><option value="<?= $id ?>"><?= $e($name) ?> (<?= (int) ($occupancy['races'][$id] ?? 0) ?>)</option><?php endforeach; ?>
    </select>
  </div>
  <div class="tbl-wrap">
  <table class="tbl" id="sl">
    <thead><tr><th>#</th><th>Jméno</th><th>Ročník</th><th>Klub / město</th><th>Trať</th><th>Platba</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr data-race="<?= (int) $r['race_id'] ?>" data-q="<?= $e(Util::fold($r['first_name'] . ' ' . $r['last_name'] . ' ' . $r['club'])) ?>">
        <td><?= $r['bib'] ? (int) $r['bib'] : $i + 1 ?></td>
        <td><b><?= $e($r['last_name']) ?></b> <?= $e($r['first_name']) ?></td>
        <td><?= (int) $r['rocnik'] ?></td>
        <td><?= $e($r['club']) ?></td>
        <td><?= $e($races[(int) $r['race_id']] ?? '') ?></td>
        <td><?= $r['status'] === 'potvrzena' ? '<span class="badge s-potvrzena">zaplaceno</span>' : '<span class="badge s-ceka_na_platbu">čeká</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (!$rows): ?><p class="card">Zatím se nikdo nepřihlásil.</p><?php endif; ?>
</article>
<script>
(function(){var s=document.getElementById('sl-search'),r=document.getElementById('sl-race');if(!s)return;
function f(){var q=s.value.normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase().trim(),rv=r.value;
document.querySelectorAll('#sl tbody tr').forEach(function(tr){var ok=(!rv||tr.dataset.race===rv)&&(!q||tr.dataset.q.indexOf(q)>=0);tr.hidden=!ok;});
if(window.bklResize)window.bklResize();}
s.addEventListener('input',f);r.addEventListener('change',f);})();
</script>
