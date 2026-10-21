<?php
/**
 * topper. admin — «posso ver / mexer neste registo?», num só sítio
 *
 * As features transversais (pesquisa ⌘K, painel lateral, edição no
 * campo, histórico, «Anular») tocam em registos de várias abas. Cada
 * registo responde à aba onde vive: uma lead à aba Leads, um negócio na
 * fase comercial (ou concluído, ou perdido) ao Pipeline, um negócio em
 * produção a Negócios. Nível da aba + âmbito (§4) — sempre no servidor.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/leads.php';

/** A linha completa da conta com sessão (papel, departamento…). */
function me_row(PDO $pdo): array {
    static $row = null;
    if ($row !== null) return $row;
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)current_user()['id']]);
    return $row = $st->fetch() ?: [];
}

/** A aba de que um registo de deals depende, pela fase. */
function deal_tab_for(string $phase): string {
    return match ($phase) {
        'lead', 'archived' => 'leads',
        'production'       => 'negocios',
        default            => 'pipeline',   // pipeline, done, lost
    };
}

/** Posso chegar a este registo de deals (lead ou negócio) com este nível? */
function record_reach(PDO $pdo, ?array $d, int $level = PERM_VIEW): bool {
    if (!$d) return false;
    $tab = deal_tab_for((string)$d['phase']);
    if (!can($tab, $level)) return false;
    if ($tab !== 'leads' && $d['code'] === null) return false;
    if ($d['owner_id'] === null) return sees_unassigned(me_row($pdo));
    return in_my_scope($pdo, $tab, (int)$d['owner_id']);
}

function record_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM deals WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Condição SQL dos registos de uma aba que posso ver (âmbito + fila sem dono para gestores). */
function reach_sql(PDO $pdo, string $tab, string $col = 'd.owner_id'): array {
    [$sql, $args] = scope_sql($pdo, $tab, $col);
    if (sees_unassigned(me_row($pdo))) $sql = "($sql OR $col IS NULL)";
    return [$sql, $args];
}
