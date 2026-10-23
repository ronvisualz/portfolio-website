<?php
/**
 * topper. admin — papéis e permissões
 *
 * Duas camadas, sempre verificadas aqui no servidor (esconder um botão
 * nunca chega):
 *   - acesso por aba: 0 sem acesso · 1 ver · 2 editar · 3 gerir;
 *   - âmbito dos registos em Leads, Pipeline, Negócios e Tarefas:
 *     own (só os meus) · dept (do meu departamento) · all (todos).
 *
 * O valor de cada aba vem, por esta ordem: exceção do utilizador →
 * linha do papel na tabela permissions → os valores por defeito abaixo.
 * Superadmin e admin têm sempre tudo; a grelha só ajusta chefe e colaborador.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/** O email da conta única de superadmin. */
const SUPERADMIN_EMAIL = 'info@topperdigital.xyz';

const PERM_NONE = 0, PERM_VIEW = 1, PERM_EDIT = 2, PERM_MANAGE = 3;

const ROLES = [
    'superadmin' => 'Superadmin',
    'admin'      => 'Admin',
    'lead'       => 'Chefe de equipa',
    'member'     => 'Colaborador',
];

/** Abas, pela ordem do menu. */
const TABS = [
    'dashboard'    => 'Dashboard',
    'leads'        => 'Leads',
    'pipeline'     => 'Pipeline',
    'contactos'    => 'Contactos',
    'negocios'     => 'Negócios',
    'orcamentacao' => 'Orçamentação',
    'criativo'     => 'Centro Criativo',
    'tarefas'      => 'Tarefas',
    'drive'        => 'Drive',
    'financeiro'   => 'Financeiro',
    'relatorios'   => 'Relatórios',
    'marketing'    => 'Marketing',
    'equipa'       => 'Equipa',
    'conhecimento' => 'Conhecimento',
    'tools'        => 'Tools',
];

/** Páginas que já existem, por aba (as restantes chegam nos passos seguintes). */
const TAB_PAGES = [
    'dashboard' => '/admin/dashboard.php',
    'leads'     => '/admin/leads.php',
    'pipeline'  => '/admin/pipeline.php',
    'contactos' => '/admin/contactos.php',
    'negocios'  => '/admin/negocios.php',
    'orcamentacao' => '/admin/orcamentacao.php',
    'criativo'  => '/admin/criativo.php',
    'tarefas'   => '/admin/calendar.php',
    'drive'     => '/admin/drive.php',
    'financeiro'=> '/admin/financeiro.php',
    'relatorios'=> '/admin/relatorios.php',
    'marketing' => '/admin/marketing.php',
    'equipa'    => '/admin/equipa.php',
    'conhecimento' => '/admin/conhecimento.php',
    'tools'     => '/admin/tools.php',
];

/** A primeira página a que o utilizador tem acesso — para onde vai depois de entrar. */
function home_url(): string {
    foreach (TAB_PAGES as $tab => $url) {
        if (can($tab)) return $url;
    }
    return '/admin/logout.php';
}

/** Abas cujos registos têm dono, e por isso âmbito. */
const SCOPED_TABS = ['leads', 'pipeline', 'negocios', 'tarefas'];

const SCOPES = ['own' => 'Só os meus', 'dept' => 'Do departamento', 'all' => 'Todos'];

/** Valores por defeito de chefe de equipa e colaborador. */
function perm_defaults(string $role): array {
    $work = ['leads', 'pipeline', 'contactos', 'negocios', 'orcamentacao', 'criativo',
             'tarefas', 'drive', 'marketing', 'conhecimento', 'tools'];
    $out = [];
    foreach (array_keys(TABS) as $t) {
        if ($role === 'lead') {
            // Dashboard, Relatórios, Equipa: ver. Financeiro: só com a permissão dada (§28 — salários e contas)
            $lvl = in_array($t, $work, true) ? PERM_EDIT : ($t === 'financeiro' ? PERM_NONE : PERM_VIEW);
        } else {
            $lvl = in_array($t, $work, true) ? PERM_EDIT : PERM_NONE; // sem Dashboard, Financeiro, Relatórios, Equipa
        }
        $out[$t] = ['level' => $lvl, 'scope' => in_array($t, SCOPED_TABS, true) ? ($role === 'lead' ? 'dept' : 'own') : null];
    }
    return $out;
}

function role_is_admin(string $role): bool {
    return $role === 'superadmin' || $role === 'admin';
}

/** A grelha efetiva de um utilizador: aba → [level, scope]. */
function perm_grid(PDO $pdo, array $user): array {
    $role = (string)$user['role'];
    if (role_is_admin($role)) {
        $all = [];
        foreach (array_keys(TABS) as $t) {
            $all[$t] = ['level' => PERM_MANAGE, 'scope' => in_array($t, SCOPED_TABS, true) ? 'all' : null];
        }
        return $all;
    }

    $grid = perm_defaults($role);
    $grid = perm_grid_rows($pdo, $role, (int)$user['id'], $grid);
    // A Equipa (o separador dos cartões) é para todos (§17.1); o resto da aba é por papel.
    $grid['equipa']['level'] = max(PERM_VIEW, $grid['equipa']['level']);
    return $grid;
}

/** Aplica à grelha as linhas do papel e as exceções do utilizador. */
function perm_grid_rows(PDO $pdo, string $role, int $uid, array $grid): array {
    $st = $pdo->prepare(
        "SELECT role, user_id, tab, level, scope FROM permissions
          WHERE (role = ? AND user_id = 0) OR (role = '' AND user_id = ?)
          ORDER BY user_id"   // linhas do papel primeiro, exceções do utilizador por cima
    );
    $st->execute([$role, $uid]);
    foreach ($st->fetchAll() as $r) {
        if (!isset($grid[$r['tab']])) continue;
        $grid[$r['tab']]['level'] = max(PERM_NONE, min(PERM_MANAGE, (int)$r['level']));
        if (in_array($r['tab'], SCOPED_TABS, true) && isset(SCOPES[$r['scope'] ?? ''])) {
            $grid[$r['tab']]['scope'] = $r['scope'];
        }
    }
    return $grid;
}

function my_grid(): array {
    global $pdo;
    static $grid = null;
    $u = current_user();
    if ($u === null) return [];
    return $grid ??= perm_grid($pdo, $u);
}

function can(string $tab, int $level = PERM_VIEW): bool {
    return (my_grid()[$tab]['level'] ?? PERM_NONE) >= $level;
}

/** Pára o pedido quando falta permissão. Usar no topo de cada página e antes de cada ação. */
function require_perm(string $tab, int $level = PERM_VIEW): void {
    if (can($tab, $level)) return;
    http_response_code(403);
    if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json' || str_ends_with((string)($_SERVER['SCRIPT_NAME'] ?? ''), '_api.php')) {
        header('Content-Type: application/json');
        exit(json_encode(['ok' => false, 'error' => 'Sem permissão.']));
    }
    exit('Sem permissão para esta área. Fale com um administrador.');
}

/**
 * Topo de cada página: ver a aba; e, num POST, editar ($editTab, se a
 * página grava noutra área — a Carteira do Painel grava negócios).
 */
function require_page(string $tab, ?string $editTab = null): void {
    require_perm($tab, PERM_VIEW);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        require_perm($editTab ?? $tab, PERM_EDIT);
    }
}

function my_scope(string $tab): string {
    return my_grid()[$tab]['scope'] ?? 'own';
}

/** Ids dos utilizadores do meu departamento (incluindo eu). */
function dept_user_ids(PDO $pdo, array $user): array {
    $dept = $user['department_id'] ?? null;
    if ($dept === null || $dept === '') return [(int)$user['id']];
    $st = $pdo->prepare('SELECT id FROM users WHERE department_id = ?');
    $st->execute([(int)$dept]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $ids[] = (int)$user['id'];
    return array_values(array_unique($ids));
}

/**
 * Condição SQL de visibilidade para a coluna de dono indicada.
 * Devolve [sql, params]; o sql é sempre seguro de pôr num WHERE.
 */
function scope_sql(PDO $pdo, string $tab, string $ownerCol): array {
    $u = current_user();
    switch (my_scope($tab)) {
        case 'all':
            return ['1=1', []];
        case 'dept':
            $ids = dept_user_ids($pdo, $u);
            return [$ownerCol . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
        default:
            return [$ownerCol . ' = ?', [(int)$u['id']]];
    }
}

/** O registo com este dono está dentro do meu âmbito? */
function in_my_scope(PDO $pdo, string $tab, ?int $ownerId): bool {
    $u = current_user();
    switch (my_scope($tab)) {
        case 'all':  return true;
        case 'dept': return $ownerId !== null && in_array($ownerId, dept_user_ids($pdo, $u), true);
        default:     return $ownerId !== null && $ownerId === (int)$u['id'];
    }
}

/**
 * Pode $actor atribuir um registo (lead, negócio, tarefa) a $target?
 *   superadmin → qualquer utilizador;
 *   admin      → admins, chefes, colaboradores;
 *   chefe      → colaboradores do seu departamento, ou a si próprio;
 *   colaborador→ só a si próprio.
 */
function can_assign_to(array $actor, array $target): bool {
    if (!empty($target['deactivated_at']) || (int)($target['is_active'] ?? 1) !== 1) return false;
    // O superadmin não é operador (§18.2): nunca é responsável de leads, negócios ou tarefas.
    if (($target['role'] ?? '') === 'superadmin') return false;
    if ((int)$actor['id'] === (int)$target['id']) return true;
    switch ($actor['role']) {
        case 'superadmin': return true;
        case 'admin':      return $target['role'] !== 'superadmin';
        case 'lead':
            return $target['role'] === 'member'
                && ($actor['department_id'] ?? null) !== null
                && (int)$actor['department_id'] === (int)($target['department_id'] ?? 0);
        default:           return false;
    }
}

/** Papéis que $actor pode dar ao criar ou editar um utilizador. */
function assignable_roles(array $actor): array {
    // Só o superadmin cria ou promove admins (§17.8).
    if ($actor['role'] === 'superadmin') return ['admin', 'lead', 'member'];
    return role_is_admin($actor['role']) ? ['lead', 'member'] : [];
}

/**
 * Pode $actor editar ou desativar $target?
 * Ninguém mexe no superadmin; só o superadmin mexe em admins;
 * admins gerem chefes e colaboradores.
 */
function can_manage_user(array $actor, array $target): bool {
    if ($target['role'] === 'superadmin') return false;
    if ($target['role'] === 'admin')      return $actor['role'] === 'superadmin';
    return role_is_admin($actor['role']);
}
