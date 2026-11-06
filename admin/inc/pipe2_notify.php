<?php
/**
 * topper. admin — Pipeline v2: avisos
 *
 * Os avisos da v2 (replaneamento, frente parada, dependência cumprida, regra «notificar», ciclo da avença) ficam na
 * tabela pipe2_notices (passo 44), um por chave (nkey, ex.: replan:<negócio>:<frente>:<sub-etapa>:<ciclo>) e para um
 * destinatário: sem destinatário não se grava. A my_alerts() junta-os ao grupo 'deal' (chave 'p2n:<id>'), por isso o
 * centro de notificações aplica as preferências do Perfil (user_wants(…, 'deal', 'app')). Sem email, como os avisos
 * de negócio de hoje.
 *
 * Uma vez por chave, também com pedidos ao mesmo tempo: um GET_LOCK por chave à volta de um INSERT … SELECT … WHERE
 * NOT EXISTS. A leitura do INSERT … SELECT bloqueia no InnoDB (vê o que outro pedido gravou, ainda que dentro de uma
 * transação que só acaba depois), e o lock evita que dois pedidos tomem o mesmo intervalo do índice e um deles leve
 * um deadlock (que desfaria a transação de quem chamou).
 *
 * Caminho v1: a my_alerts() chama pipe2_notices_for() para toda a gente; numa base sem a tabela (antes do passo 44)
 * dá [] sem consultar mais nada. A verificação da tabela guarda-se só quando é verdadeira, por ligação: uma ligação
 * que a viu em falta vê-a assim que o passo 44 corre. Antes do «Aplicar» não há negócios v2, por isso não há avisos.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';

/** O ícone de cada tipo de aviso (os tipos são os de PIPE2_CODES['pipe2_notices.kind']). */
const PIPE2_NOTICE_ICONS = ['replan' => 'clock', 'stale' => 'pause', 'dep_met' => 'check', 'rule' => 'bell', 'cycle' => 'refresh'];

/**
 * A chave de um aviso: "kind:negócio:frente:sub-etapa:ciclo", mais ":regra" quando há regra (0 para a frente e a
 * sub-etapa nulas). Nunca passa os 64 caracteres da coluna: uma chave maior fica "kind:" + sha1 dela.
 */
function pipe2_notice_key(string $kind, int $dealId, ?int $lineId, ?int $stageId, string $cycle = '', ?int $ruleId = null): string {
    $k = $kind . ':' . $dealId . ':' . (int)$lineId . ':' . (int)$stageId . ':' . $cycle . ($ruleId !== null ? ':' . $ruleId : '');
    return strlen($k) <= 64 ? $k : substr($kind, 0, 10) . ':' . sha1($k);
}

/** A tabela dos avisos existe nesta ligação. Guarda só o verdadeiro (referência fraca, como pipe2_schema_ready). */
function pipe2_notices_ready(PDO $pdo): bool {
    static $ready = [];
    $id = spl_object_id($pdo);
    if (isset($ready[$id]) && $ready[$id]->get() === $pdo) return true;
    try {
        $ok = db_has_table($pdo, 'pipe2_notices');
    } catch (Throwable $e) {
        return false;
    }
    if ($ok) $ready[$id] = WeakReference::create($pdo);
    else unset($ready[$id]);
    return $ok;
}

/**
 * Grava um aviso. $n: deal_id, line_id, stage_id, cycle_ym ('' ou AAAA-MM), kind, rule_id, user_id, title, body,
 * nkey (vazia ou ausente = pipe2_notice_key pelos campos). Devolve o id, ou null sem destinatário, com a chave já
 * gravada ou sem a tabela. Lança InvalidArgumentException no que a coluna cortaria (tipo desconhecido, chave com mais
 * de 64, ciclo fora de AAAA-MM) e sem negócio. O título e o corpo cortam-se nas larguras (200 e 255).
 */
function pipe2_notice_add(PDO $pdo, array $n): ?int {
    $kind = (string)($n['kind'] ?? '');
    if (!in_array($kind, PIPE2_CODES['pipe2_notices.kind'], true)) throw new InvalidArgumentException('Aviso: tipo desconhecido «' . $kind . '».');
    $dealId = (int)($n['deal_id'] ?? 0);
    if ($dealId <= 0) throw new InvalidArgumentException('Aviso: falta o negócio.');
    $cycle = (string)($n['cycle_ym'] ?? '');
    if (!preg_match('/^(\d{4}-\d{2})?$/', $cycle)) throw new InvalidArgumentException('Aviso: ciclo inválido «' . $cycle . '».');
    $lineId = isset($n['line_id']) ? (int)$n['line_id'] : null;
    $stageId = isset($n['stage_id']) ? (int)$n['stage_id'] : null;
    $ruleId = isset($n['rule_id']) ? (int)$n['rule_id'] : null;
    $nkey = (string)($n['nkey'] ?? '');
    if ($nkey === '') $nkey = pipe2_notice_key($kind, $dealId, $lineId, $stageId, $cycle, $ruleId);
    if (strlen($nkey) > 64) throw new InvalidArgumentException('Aviso: a chave tem mais de 64 caracteres.');
    $uid = (int)($n['user_id'] ?? 0);
    if ($uid <= 0 || !pipe2_notices_ready($pdo)) return null;

    $lock = pipe2_notice_lock($pdo, $nkey);
    try {
        $st = $pdo->prepare('INSERT INTO pipe2_notices (deal_id, line_id, stage_id, cycle_ym, kind, rule_id, user_id, title, body, nkey, created_at)
                             SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . (db_is_sqlite($pdo) ? '' : ' FROM DUAL') . '
                              WHERE NOT EXISTS (SELECT 1 FROM pipe2_notices WHERE nkey = ?)');
        $st->execute([$dealId, $lineId, $stageId, $cycle, $kind, $ruleId, $uid,
                      mb_substr((string)($n['title'] ?? ''), 0, 200), mb_substr((string)($n['body'] ?? ''), 0, 255), $nkey, date('Y-m-d H:i:s'), $nkey]);
        return $st->rowCount() === 1 ? (int)$pdo->lastInsertId() : null;
    } finally {
        if ($lock !== '') $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}

/**
 * O lock de uma chave (até 5 s), pela base e pela chave; '' em SQLite ou se não o obtiver a tempo (o INSERT … WHERE
 * NOT EXISTS continua a não duplicar; o lock só tira o deadlock do caminho).
 */
function pipe2_notice_lock(PDO $pdo, string $nkey): string {
    if (db_is_sqlite($pdo)) return '';
    $name = 'topper_p2n_' . md5((string)$pdo->query('SELECT DATABASE()')->fetchColumn() . '|' . $nkey);
    $st = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $st->execute([$name]);
    $got = $st->fetchColumn();
    $st->closeCursor();
    return (string)$got === '1' ? $name : '';
}

/**
 * Os avisos de um utilizador dos últimos $days dias, por data, como itens de my_alerts: key 'p2n:<id>', kind 'deal',
 * icon, sev 'warn', title, sub (o corpo), date (AAAA-MM-DD), url (a ficha do negócio). Só os de negócios que existem.
 * Sem a tabela → [].
 */
function pipe2_notices_for(PDO $pdo, int $uid, int $days = 14): array {
    if ($uid <= 0 || !pipe2_notices_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT n.id, n.deal_id, n.kind, n.title, n.body, n.created_at FROM pipe2_notices n JOIN deals d ON d.id = n.deal_id
                          WHERE n.user_id = ? AND n.created_at >= ? ORDER BY n.created_at, n.id');
    $st->execute([$uid, date('Y-m-d H:i:s', time() - max(0, $days) * 86400)]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['key' => 'p2n:' . (int)$r['id'], 'kind' => 'deal', 'icon' => PIPE2_NOTICE_ICONS[$r['kind']] ?? 'bell', 'sev' => 'warn',
                  'title' => (string)$r['title'], 'sub' => (string)$r['body'], 'date' => substr((string)$r['created_at'], 0, 10),
                  'url' => '/admin/negocio.php?id=' . (int)$r['deal_id']];
    }
    return $out;
}
