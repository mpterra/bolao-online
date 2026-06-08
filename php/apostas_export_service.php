<?php
declare(strict_types=1);

const APOSTAS_EXPORT_KNOCKOUT_PHASES = [
    '16_DE_FINAL',
    'OITAVAS',
    'QUARTAS',
    'SEMI',
    'TERCEIRO_LUGAR',
    'FINAL',
];

const APOSTAS_EXPORT_KNOCKOUT_PHASE_LABELS = [
    '16_DE_FINAL' => '16 de final',
    'OITAVAS' => 'Oitavas',
    'QUARTAS' => 'Quartas',
    'SEMI' => 'Semifinal',
    'TERCEIRO_LUGAR' => '3o lugar',
    'FINAL' => 'Final',
];

function apostas_export_log(string $message, array $context = []): void
{
    $parts = [];

    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $parts[] = $key . '=' . var_export($value, true);
            continue;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $parts[] = $key . '=' . ($json !== false ? $json : '[unserializable]');
    }

    error_log('[apostas_export] ' . $message . (count($parts) > 0 ? ' | ' . implode(' ', $parts) : ''));
}

function apostas_export_fail_integrity(string $message, array $context = []): void
{
    apostas_export_log($message, $context);
    throw new DomainException($message);
}

function apostas_export_require_pdo(mixed $pdo): PDO
{
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('PDO nao inicializado em conexao.php');
    }

    return $pdo;
}

function apostas_export_begin_snapshot(PDO $pdo): bool
{
    if ($pdo->inTransaction()) {
        return false;
    }

    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();

    return true;
}

function apostas_export_commit_snapshot(PDO $pdo, bool $started): void
{
    if ($started && $pdo->inTransaction()) {
        $pdo->commit();
    }
}

function apostas_export_rollback_snapshot(?PDO $pdo, bool $started): void
{
    if ($started && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

function apostas_export_active_edicao_id(PDO $pdo): int
{
    $edicaoId = (int)$pdo->query('SELECT id FROM edicoes WHERE ativo = 1 ORDER BY ano DESC LIMIT 1')->fetchColumn();
    if ($edicaoId <= 0) {
        $edicaoId = (int)$pdo->query('SELECT id FROM edicoes ORDER BY ano DESC LIMIT 1')->fetchColumn();
    }
    if ($edicaoId <= 0) {
        throw new RuntimeException('Nenhuma edicao encontrada.');
    }

    return $edicaoId;
}

function apostas_export_strh(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function apostas_export_filename_slug(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'usuario';
    }

    $value = mb_strtolower($value, 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false && $ascii !== '') {
        $value = $ascii;
    }

    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
    $value = trim($value, '_');

    return $value !== '' ? $value : 'usuario';
}

function apostas_export_format_datetime(?string $value, string $format = 'd/m/Y H:i'): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }

    return date($format, $ts);
}

function apostas_export_knockout_phase_labels(): array
{
    return APOSTAS_EXPORT_KNOCKOUT_PHASE_LABELS;
}

function apostas_export_load_user(PDO $pdo, int $userId, bool $requireActive = false): array
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Parametro usuario_id invalido.');
    }

    $sql = '
        SELECT id, nome, tipo_usuario, ativo
        FROM usuarios
        WHERE id = :id
        LIMIT 1
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        throw new OutOfBoundsException('Usuario nao encontrado.');
    }

    $normalized = [
        'id' => (int)($row['id'] ?? 0),
        'nome' => (string)($row['nome'] ?? ''),
        'tipo_usuario' => (string)($row['tipo_usuario'] ?? ''),
        'ativo' => (int)($row['ativo'] ?? 0),
    ];

    if ($normalized['id'] <= 0) {
        throw new RuntimeException('ID de usuario invalido no banco.');
    }

    if ($requireActive && $normalized['ativo'] !== 1) {
        throw new OutOfBoundsException('Usuario inativo.');
    }

    return $normalized;
}

function apostas_export_load_active_users(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, nome, tipo_usuario, ativo
        FROM usuarios
        WHERE ativo = 1
        ORDER BY (tipo_usuario='ADMIN') DESC, nome ASC, id ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $users = [];
    foreach ($rows as $row) {
        $user = [
            'id' => (int)($row['id'] ?? 0),
            'nome' => (string)($row['nome'] ?? ''),
            'tipo_usuario' => (string)($row['tipo_usuario'] ?? ''),
            'ativo' => (int)($row['ativo'] ?? 0),
        ];

        if ($user['id'] <= 0) {
            apostas_export_fail_integrity('Usuario ativo com id invalido.', ['row' => $row]);
        }

        $users[] = $user;
    }

    return $users;
}

function apostas_export_int_or_null(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_int($value)) {
        return $value;
    }

    if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
        return (int)$value;
    }

    if (is_float($value)) {
        return (int)$value;
    }

    return null;
}

function apostas_export_assert_score_pair(?int $golsCasa, ?int $golsFora, string $scope, array $context = []): void
{
    $isPartial = ($golsCasa === null xor $golsFora === null);
    if ($isPartial) {
        apostas_export_fail_integrity(
            'Palpite com placar parcial detectado.',
            ['scope' => $scope, 'gols_casa' => $golsCasa, 'gols_fora' => $golsFora] + $context
        );
    }
}

function apostas_export_load_group_index(PDO $pdo, int $edicaoId): array
{
    $stmtGroups = $pdo->prepare('
        SELECT id, codigo
        FROM grupos
        WHERE edicao_id = :edicao_id
        ORDER BY codigo, id
    ');
    $stmtGroups->execute([':edicao_id' => $edicaoId]);
    $groupRows = $stmtGroups->fetchAll(PDO::FETCH_ASSOC);

    $groupsById = [];
    foreach ($groupRows as $row) {
        $groupId = (int)($row['id'] ?? 0);
        $code = trim((string)($row['codigo'] ?? ''));

        if ($groupId <= 0 || $code === '') {
            apostas_export_fail_integrity('Grupo invalido na edicao.', ['edicao_id' => $edicaoId, 'row' => $row]);
        }

        $groupsById[$groupId] = [
            'id' => $groupId,
            'codigo' => $code,
            'team_ids' => [],
            'team_names' => [],
        ];
    }

    $stmtTeams = $pdo->prepare('
        SELECT gt.grupo_id, gt.time_id, t.nome AS time_nome
        FROM grupo_time gt
        INNER JOIN grupos g
            ON g.id = gt.grupo_id
           AND g.edicao_id = :edicao_id1
        INNER JOIN times t ON t.id = gt.time_id
        WHERE gt.edicao_id = :edicao_id2
        ORDER BY gt.grupo_id, gt.time_id
    ');
    $stmtTeams->execute([
        ':edicao_id1' => $edicaoId,
        ':edicao_id2' => $edicaoId,
    ]);

    $editionTeamIds = [];
    foreach ($stmtTeams->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $groupId = (int)($row['grupo_id'] ?? 0);
        $timeId = (int)($row['time_id'] ?? 0);
        $timeName = (string)($row['time_nome'] ?? '');

        if (!isset($groupsById[$groupId])) {
            apostas_export_fail_integrity(
                'Grupo_time aponta para grupo fora da edicao.',
                ['edicao_id' => $edicaoId, 'grupo_id' => $groupId, 'time_id' => $timeId]
            );
        }

        $groupsById[$groupId]['team_ids'][$timeId] = true;
        $groupsById[$groupId]['team_names'][$timeId] = $timeName;
        $editionTeamIds[$timeId] = true;
    }

    return [
        'groups_by_id' => $groupsById,
        'edition_team_ids' => $editionTeamIds,
    ];
}

function apostas_export_load_classificacao(PDO $pdo, int $edicaoId, int $userId, array $groupIndex): array
{
    $stmt = $pdo->prepare('
        SELECT
            g.id AS grupo_id,
            g.codigo AS grupo_codigo,
            pgc.primeiro_time_id,
            pgc.segundo_time_id,
            pgc.terceiro_time_id,
            t1.nome AS primeiro_nome,
            t2.nome AS segundo_nome,
            t3.nome AS terceiro_nome
        FROM grupos g
        LEFT JOIN palpite_grupo_classificacao pgc
               ON pgc.grupo_id = g.id
              AND pgc.usuario_id = :usuario_id
              AND pgc.edicao_id = :edicao_id1
        LEFT JOIN times t1 ON t1.id = pgc.primeiro_time_id
        LEFT JOIN times t2 ON t2.id = pgc.segundo_time_id
        LEFT JOIN times t3 ON t3.id = pgc.terceiro_time_id
        WHERE g.edicao_id = :edicao_id2
        ORDER BY g.codigo, g.id
    ');
    $stmt->execute([
        ':usuario_id' => $userId,
        ':edicao_id1' => $edicaoId,
        ':edicao_id2' => $edicaoId,
    ]);

    $rows = [];
    $seenGroups = [];
    $groupsById = $groupIndex['groups_by_id'] ?? [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $groupId = (int)($row['grupo_id'] ?? 0);
        $groupCode = (string)($row['grupo_codigo'] ?? '');

        if ($groupId <= 0 || !isset($groupsById[$groupId])) {
            apostas_export_fail_integrity(
                'Classificacao retornou grupo invalido.',
                ['edicao_id' => $edicaoId, 'usuario_id' => $userId, 'row' => $row]
            );
        }

        if (isset($seenGroups[$groupId])) {
            apostas_export_fail_integrity(
                'Classificacao duplicada para o mesmo grupo.',
                ['edicao_id' => $edicaoId, 'usuario_id' => $userId, 'grupo_id' => $groupId]
            );
        }
        $seenGroups[$groupId] = true;

        $primeiroId = apostas_export_int_or_null($row['primeiro_time_id'] ?? null);
        $segundoId = apostas_export_int_or_null($row['segundo_time_id'] ?? null);
        $terceiroId = apostas_export_int_or_null($row['terceiro_time_id'] ?? null);

        $selectedIds = array_values(array_filter([$primeiroId, $segundoId, $terceiroId], static fn (?int $id): bool => $id !== null));
        if (count($selectedIds) > 0 && count($selectedIds) !== 3) {
            apostas_export_fail_integrity(
                'Classificacao parcial detectada.',
                ['edicao_id' => $edicaoId, 'usuario_id' => $userId, 'grupo_id' => $groupId]
            );
        }

        if (count($selectedIds) !== count(array_unique($selectedIds))) {
            apostas_export_fail_integrity(
                'Classificacao com times repetidos detectada.',
                ['edicao_id' => $edicaoId, 'usuario_id' => $userId, 'grupo_id' => $groupId]
            );
        }

        foreach ($selectedIds as $selectedId) {
            if (!isset($groupsById[$groupId]['team_ids'][$selectedId])) {
                apostas_export_fail_integrity(
                    'Classificacao aponta time fora do grupo.',
                    [
                        'edicao_id' => $edicaoId,
                        'usuario_id' => $userId,
                        'grupo_id' => $groupId,
                        'time_id' => $selectedId,
                    ]
                );
            }
        }

        $rows[] = [
            'grupo_id' => $groupId,
            'grupo_codigo' => $groupCode,
            'primeiro_time_id' => $primeiroId,
            'segundo_time_id' => $segundoId,
            'terceiro_time_id' => $terceiroId,
            'primeiro_nome' => (string)($row['primeiro_nome'] ?? ''),
            'segundo_nome' => (string)($row['segundo_nome'] ?? ''),
            'terceiro_nome' => (string)($row['terceiro_nome'] ?? ''),
        ];
    }

    return $rows;
}

function apostas_export_load_campeao(PDO $pdo, int $edicaoId, int $userId, array $editionTeamIds): array
{
    $stmt = $pdo->prepare('
        SELECT pc.time_id, t.nome AS campeao_nome
        FROM palpite_campeao pc
        INNER JOIN times t ON t.id = pc.time_id
        WHERE pc.usuario_id = :usuario_id
          AND pc.edicao_id = :edicao_id
    ');
    $stmt->execute([
        ':usuario_id' => $userId,
        ':edicao_id' => $edicaoId,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > 1) {
        apostas_export_fail_integrity(
            'Mais de um palpite de campeao para o mesmo usuario.',
            ['edicao_id' => $edicaoId, 'usuario_id' => $userId]
        );
    }

    if (count($rows) === 0) {
        return ['time_id' => null, 'nome' => ''];
    }

    $timeId = (int)($rows[0]['time_id'] ?? 0);
    if ($timeId <= 0 || !isset($editionTeamIds[$timeId])) {
        apostas_export_fail_integrity(
            'Campeao fora da edicao detectado.',
            ['edicao_id' => $edicaoId, 'usuario_id' => $userId, 'time_id' => $timeId]
        );
    }

    return [
        'time_id' => $timeId,
        'nome' => (string)($rows[0]['campeao_nome'] ?? ''),
    ];
}

function apostas_export_load_top4(PDO $pdo, int $edicaoId, int $userId, array $editionTeamIds): array
{
    $stmt = $pdo->prepare('
        SELECT
            pt.primeiro_time_id,
            pt.segundo_time_id,
            pt.terceiro_time_id,
            pt.quarto_time_id,
            t1.nome AS primeiro_nome,
            t2.nome AS segundo_nome,
            t3.nome AS terceiro_nome,
            t4.nome AS quarto_nome
        FROM palpite_top4 pt
        LEFT JOIN times t1 ON t1.id = pt.primeiro_time_id
        LEFT JOIN times t2 ON t2.id = pt.segundo_time_id
        LEFT JOIN times t3 ON t3.id = pt.terceiro_time_id
        LEFT JOIN times t4 ON t4.id = pt.quarto_time_id
        WHERE pt.usuario_id = :usuario_id
          AND pt.edicao_id = :edicao_id
    ');
    $stmt->execute([
        ':usuario_id' => $userId,
        ':edicao_id' => $edicaoId,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > 1) {
        apostas_export_fail_integrity(
            'Mais de um top 4 para o mesmo usuario.',
            ['edicao_id' => $edicaoId, 'usuario_id' => $userId]
        );
    }

    if (count($rows) === 0) {
        return [
            'primeiro_time_id' => null,
            'segundo_time_id' => null,
            'terceiro_time_id' => null,
            'quarto_time_id' => null,
            'primeiro_nome' => '',
            'segundo_nome' => '',
            'terceiro_nome' => '',
            'quarto_nome' => '',
        ];
    }

    $row = $rows[0];
    $ids = [
        'primeiro_time_id' => (int)($row['primeiro_time_id'] ?? 0),
        'segundo_time_id' => (int)($row['segundo_time_id'] ?? 0),
        'terceiro_time_id' => (int)($row['terceiro_time_id'] ?? 0),
        'quarto_time_id' => (int)($row['quarto_time_id'] ?? 0),
    ];

    if (count(array_unique(array_values($ids))) !== 4) {
        apostas_export_fail_integrity(
            'Top 4 com times repetidos detectado.',
            ['edicao_id' => $edicaoId, 'usuario_id' => $userId]
        );
    }

    foreach ($ids as $key => $timeId) {
        if ($timeId <= 0 || !isset($editionTeamIds[$timeId])) {
            apostas_export_fail_integrity(
                'Top 4 aponta time fora da edicao.',
                ['edicao_id' => $edicaoId, 'usuario_id' => $userId, 'campo' => $key, 'time_id' => $timeId]
            );
        }
    }

    return [
        'primeiro_time_id' => $ids['primeiro_time_id'],
        'segundo_time_id' => $ids['segundo_time_id'],
        'terceiro_time_id' => $ids['terceiro_time_id'],
        'quarto_time_id' => $ids['quarto_time_id'],
        'primeiro_nome' => (string)($row['primeiro_nome'] ?? ''),
        'segundo_nome' => (string)($row['segundo_nome'] ?? ''),
        'terceiro_nome' => (string)($row['terceiro_nome'] ?? ''),
        'quarto_nome' => (string)($row['quarto_nome'] ?? ''),
    ];
}

function apostas_export_load_jogos_grupos(PDO $pdo, int $edicaoId, int $userId, array $groupIndex): array
{
    $stmt = $pdo->prepare("
        SELECT
            j.id AS jogo_id,
            j.grupo_id,
            g.codigo AS grupo_codigo,
            j.data_hora,
            j.codigo_fifa,
            j.time_casa_id,
            j.time_fora_id,
            tc.nome AS casa_nome,
            tf.nome AS fora_nome,
            p.gols_casa,
            p.gols_fora
        FROM jogos j
        INNER JOIN grupos g
            ON g.id = j.grupo_id
           AND g.edicao_id = :edicao_id1
        INNER JOIN times tc ON tc.id = j.time_casa_id
        INNER JOIN times tf ON tf.id = j.time_fora_id
        LEFT JOIN palpites p
            ON p.jogo_id = j.id
           AND p.usuario_id = :usuario_id
        WHERE j.edicao_id = :edicao_id2
          AND j.grupo_id IS NOT NULL
          AND (j.fase = 'GRUPOS' OR j.fase = 'GRUPO' OR j.fase = 'FASE_DE_GRUPOS' OR j.fase LIKE '%GRUP%')
        ORDER BY g.codigo, j.data_hora, j.id
    ");
    $stmt->execute([
        ':edicao_id1' => $edicaoId,
        ':edicao_id2' => $edicaoId,
        ':usuario_id' => $userId,
    ]);

    $rows = [];
    $seenGames = [];
    $groupsById = $groupIndex['groups_by_id'] ?? [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $gameId = (int)($row['jogo_id'] ?? 0);
        $groupId = (int)($row['grupo_id'] ?? 0);
        $timeCasaId = (int)($row['time_casa_id'] ?? 0);
        $timeForaId = (int)($row['time_fora_id'] ?? 0);

        if ($gameId <= 0 || isset($seenGames[$gameId])) {
            apostas_export_fail_integrity(
                'Jogo de grupos invalido ou duplicado no export.',
                ['edicao_id' => $edicaoId, 'usuario_id' => $userId, 'jogo_id' => $gameId]
            );
        }
        $seenGames[$gameId] = true;

        if (!isset($groupsById[$groupId])) {
            apostas_export_fail_integrity(
                'Jogo de grupos aponta grupo invalido.',
                ['edicao_id' => $edicaoId, 'usuario_id' => $userId, 'jogo_id' => $gameId, 'grupo_id' => $groupId]
            );
        }

        if (!isset($groupsById[$groupId]['team_ids'][$timeCasaId]) || !isset($groupsById[$groupId]['team_ids'][$timeForaId])) {
            apostas_export_fail_integrity(
                'Jogo de grupos com time fora do grupo.',
                [
                    'edicao_id' => $edicaoId,
                    'usuario_id' => $userId,
                    'jogo_id' => $gameId,
                    'grupo_id' => $groupId,
                    'time_casa_id' => $timeCasaId,
                    'time_fora_id' => $timeForaId,
                ]
            );
        }

        $golsCasa = apostas_export_int_or_null($row['gols_casa'] ?? null);
        $golsFora = apostas_export_int_or_null($row['gols_fora'] ?? null);
        apostas_export_assert_score_pair($golsCasa, $golsFora, 'grupos', [
            'edicao_id' => $edicaoId,
            'usuario_id' => $userId,
            'jogo_id' => $gameId,
        ]);

        $rows[] = [
            'jogo_id' => $gameId,
            'grupo_id' => $groupId,
            'grupo_codigo' => (string)($row['grupo_codigo'] ?? ''),
            'data_hora' => (string)($row['data_hora'] ?? ''),
            'codigo_fifa' => trim((string)($row['codigo_fifa'] ?? '')),
            'time_casa_id' => $timeCasaId,
            'time_fora_id' => $timeForaId,
            'casa_nome' => (string)($row['casa_nome'] ?? ''),
            'fora_nome' => (string)($row['fora_nome'] ?? ''),
            'gols_casa' => $golsCasa,
            'gols_fora' => $golsFora,
        ];
    }

    return $rows;
}

function apostas_export_load_jogos_mata_mata(PDO $pdo, int $edicaoId, int $userId, array $editionTeamIds): array
{
    $stmt = $pdo->prepare("
        SELECT
            j.id AS jogo_id,
            j.fase,
            j.data_hora,
            j.codigo_fifa,
            j.time_casa_id,
            j.time_fora_id,
            tc.nome AS casa_nome,
            tf.nome AS fora_nome,
            p.gols_casa,
            p.gols_fora,
            p.passa_time_id,
            tp.nome AS passa_nome
        FROM jogos j
        INNER JOIN times tc ON tc.id = j.time_casa_id
        INNER JOIN times tf ON tf.id = j.time_fora_id
        LEFT JOIN palpites p
            ON p.jogo_id = j.id
           AND p.usuario_id = :usuario_id
        LEFT JOIN times tp ON tp.id = p.passa_time_id
        WHERE j.edicao_id = :edicao_id
          AND j.grupo_id IS NULL
          AND j.fase IN ('16_DE_FINAL','OITAVAS','QUARTAS','SEMI','TERCEIRO_LUGAR','FINAL')
        ORDER BY
          FIELD(j.fase,'16_DE_FINAL','OITAVAS','QUARTAS','SEMI','TERCEIRO_LUGAR','FINAL'),
          j.data_hora, j.id
    ");
    $stmt->execute([
        ':edicao_id' => $edicaoId,
        ':usuario_id' => $userId,
    ]);

    $rows = [];
    $seenGames = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $gameId = (int)($row['jogo_id'] ?? 0);
        $timeCasaId = (int)($row['time_casa_id'] ?? 0);
        $timeForaId = (int)($row['time_fora_id'] ?? 0);
        $passaTimeId = apostas_export_int_or_null($row['passa_time_id'] ?? null);
        $golsCasa = apostas_export_int_or_null($row['gols_casa'] ?? null);
        $golsFora = apostas_export_int_or_null($row['gols_fora'] ?? null);

        if ($gameId <= 0 || isset($seenGames[$gameId])) {
            apostas_export_fail_integrity(
                'Jogo de mata-mata invalido ou duplicado no export.',
                ['edicao_id' => $edicaoId, 'usuario_id' => $userId, 'jogo_id' => $gameId]
            );
        }
        $seenGames[$gameId] = true;

        if (!isset($editionTeamIds[$timeCasaId]) || !isset($editionTeamIds[$timeForaId])) {
            apostas_export_fail_integrity(
                'Jogo de mata-mata com time fora da edicao.',
                [
                    'edicao_id' => $edicaoId,
                    'usuario_id' => $userId,
                    'jogo_id' => $gameId,
                    'time_casa_id' => $timeCasaId,
                    'time_fora_id' => $timeForaId,
                ]
            );
        }

        apostas_export_assert_score_pair($golsCasa, $golsFora, 'mata_mata', [
            'edicao_id' => $edicaoId,
            'usuario_id' => $userId,
            'jogo_id' => $gameId,
        ]);

        if ($passaTimeId !== null && $passaTimeId !== $timeCasaId && $passaTimeId !== $timeForaId) {
            apostas_export_fail_integrity(
                'Desempate aponta time fora do jogo.',
                [
                    'edicao_id' => $edicaoId,
                    'usuario_id' => $userId,
                    'jogo_id' => $gameId,
                    'passa_time_id' => $passaTimeId,
                    'time_casa_id' => $timeCasaId,
                    'time_fora_id' => $timeForaId,
                ]
            );
        }

        if ($passaTimeId !== null && ($golsCasa === null || $golsFora === null)) {
            apostas_export_fail_integrity(
                'Desempate salvo sem placar completo.',
                ['edicao_id' => $edicaoId, 'usuario_id' => $userId, 'jogo_id' => $gameId]
            );
        }

        if ($golsCasa !== null && $golsFora !== null && $golsCasa === $golsFora && $passaTimeId === null) {
            apostas_export_fail_integrity(
                'Empate salvo sem desempate definido.',
                ['edicao_id' => $edicaoId, 'usuario_id' => $userId, 'jogo_id' => $gameId]
            );
        }

        $rows[] = [
            'jogo_id' => $gameId,
            'fase' => (string)($row['fase'] ?? ''),
            'data_hora' => (string)($row['data_hora'] ?? ''),
            'codigo_fifa' => trim((string)($row['codigo_fifa'] ?? '')),
            'time_casa_id' => $timeCasaId,
            'time_fora_id' => $timeForaId,
            'casa_nome' => (string)($row['casa_nome'] ?? ''),
            'fora_nome' => (string)($row['fora_nome'] ?? ''),
            'gols_casa' => $golsCasa,
            'gols_fora' => $golsFora,
            'passa_time_id' => $passaTimeId,
            'passa_nome' => (string)($row['passa_nome'] ?? ''),
        ];
    }

    return $rows;
}

function apostas_export_load_bundle(PDO $pdo, int $edicaoId, array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        throw new InvalidArgumentException('Usuario invalido para exportacao.');
    }

    $groupIndex = apostas_export_load_group_index($pdo, $edicaoId);
    $classificacao = apostas_export_load_classificacao($pdo, $edicaoId, $userId, $groupIndex);
    $campeao = apostas_export_load_campeao($pdo, $edicaoId, $userId, $groupIndex['edition_team_ids']);
    $top4 = apostas_export_load_top4($pdo, $edicaoId, $userId, $groupIndex['edition_team_ids']);
    $jogosGrupos = apostas_export_load_jogos_grupos($pdo, $edicaoId, $userId, $groupIndex);
    $jogosMataMata = apostas_export_load_jogos_mata_mata($pdo, $edicaoId, $userId, $groupIndex['edition_team_ids']);

    return [
        'edicao_id' => $edicaoId,
        'usuario' => $user,
        'campeao_time_id' => $campeao['time_id'],
        'campeao_nome' => $campeao['nome'],
        'classificacao' => $classificacao,
        'top4' => $top4,
        'jogos_grupos' => $jogosGrupos,
        'jogos_mata_mata' => $jogosMataMata,
    ];
}

function apostas_export_render_excel_html(array $bundle): string
{
    $usuario = $bundle['usuario'] ?? [];
    $usuarioId = (int)($usuario['id'] ?? 0);
    $usuarioNome = (string)($usuario['nome'] ?? '');
    $edicaoId = (int)($bundle['edicao_id'] ?? 0);
    $campeaoNome = (string)($bundle['campeao_nome'] ?? '');
    $rowsClass = is_array($bundle['classificacao'] ?? null) ? $bundle['classificacao'] : [];
    $rowsJogos = is_array($bundle['jogos_grupos'] ?? null) ? $bundle['jogos_grupos'] : [];
    $rowTop4 = is_array($bundle['top4'] ?? null) ? $bundle['top4'] : [];
    $rowsJogosMM = is_array($bundle['jogos_mata_mata'] ?? null) ? $bundle['jogos_mata_mata'] : [];
    $faseLabel = apostas_export_knockout_phase_labels();

    ob_start();
    echo "<html><head><meta charset='UTF-8'></head><body>";

    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<tbody>";
    echo "<tr><th colspan='4'>Exportacao de Apostas</th></tr>";
    echo "<tr>";
    echo "<td><b>Usuario</b></td>";
    echo "<td>" . apostas_export_strh($usuarioNome) . "</td>";
    echo "<td><b>ID</b></td>";
    echo "<td>" . apostas_export_strh((string)$usuarioId) . "</td>";
    echo "</tr>";
    echo "<tr><td><b>Edicao ID</b></td><td colspan='3'>" . apostas_export_strh((string)$edicaoId) . "</td></tr>";
    echo "<tr><td><b>Campeao</b></td><td colspan='3'>" . apostas_export_strh($campeaoNome) . "</td></tr>";
    echo "</tbody>";
    echo "</table>";

    echo "<br/>";

    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<thead>";
    echo "<tr><th colspan='4'>Palpite de Classificacao por Grupo (1o / 2o / 3o)</th></tr>";
    echo "<tr><th>Grupo</th><th>1o</th><th>2o</th><th>3o</th></tr>";
    echo "</thead>";
    echo "<tbody>";

    foreach ($rowsClass as $row) {
        echo "<tr>";
        echo "<td>" . apostas_export_strh((string)($row['grupo_codigo'] ?? '')) . "</td>";
        echo "<td>" . apostas_export_strh((string)($row['primeiro_nome'] ?? '')) . "</td>";
        echo "<td>" . apostas_export_strh((string)($row['segundo_nome'] ?? '')) . "</td>";
        echo "<td>" . apostas_export_strh((string)($row['terceiro_nome'] ?? '')) . "</td>";
        echo "</tr>";
    }

    echo "</tbody>";
    echo "</table>";
    echo "<br/>";

    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<thead>";
    echo "<tr><th colspan='7'>Palpites de Jogos (Fase de Grupos)</th></tr>";
    echo "<tr>";
    echo "<th>Grupo</th>";
    echo "<th>Data/Hora</th>";
    echo "<th>Codigo FIFA</th>";
    echo "<th>Time da casa</th>";
    echo "<th>Placar casa</th>";
    echo "<th>Time visitante</th>";
    echo "<th>Placar visitante</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";

    foreach ($rowsJogos as $row) {
        $golsCasa = apostas_export_int_or_null($row['gols_casa'] ?? null);
        $golsFora = apostas_export_int_or_null($row['gols_fora'] ?? null);

        echo "<tr>";
        echo "<td>" . apostas_export_strh((string)($row['grupo_codigo'] ?? '')) . "</td>";
        echo "<td>" . apostas_export_strh(apostas_export_format_datetime((string)($row['data_hora'] ?? ''))) . "</td>";
        echo "<td>" . apostas_export_strh((string)($row['codigo_fifa'] ?? '')) . "</td>";
        echo "<td>" . apostas_export_strh((string)($row['casa_nome'] ?? '')) . "</td>";
        echo "<td>" . apostas_export_strh($golsCasa === null ? '' : (string)$golsCasa) . "</td>";
        echo "<td>" . apostas_export_strh((string)($row['fora_nome'] ?? '')) . "</td>";
        echo "<td>" . apostas_export_strh($golsFora === null ? '' : (string)$golsFora) . "</td>";
        echo "</tr>";
    }

    echo "</tbody>";
    echo "</table>";

    echo "<br/><br/>";

    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<thead>";
    echo "<tr><th colspan='4'>Top 4 (Mata-mata)</th></tr>";
    echo "<tr><th>1o</th><th>2o</th><th>3o</th><th>4o</th></tr>";
    echo "</thead>";
    echo "<tbody>";
    echo "<tr>";
    echo "<td>" . apostas_export_strh((string)($rowTop4['primeiro_nome'] ?? '')) . "</td>";
    echo "<td>" . apostas_export_strh((string)($rowTop4['segundo_nome'] ?? '')) . "</td>";
    echo "<td>" . apostas_export_strh((string)($rowTop4['terceiro_nome'] ?? '')) . "</td>";
    echo "<td>" . apostas_export_strh((string)($rowTop4['quarto_nome'] ?? '')) . "</td>";
    echo "</tr>";
    echo "</tbody>";
    echo "</table>";

    echo "<br/>";

    echo "<table border='1' cellspacing='0' cellpadding='6'>";
    echo "<thead>";
    echo "<tr><th colspan='8'>Palpites de Jogos (Mata-mata)</th></tr>";
    echo "<tr>";
    echo "<th>Fase</th>";
    echo "<th>Data/Hora</th>";
    echo "<th>Codigo FIFA</th>";
    echo "<th>Time da casa</th>";
    echo "<th>Placar casa</th>";
    echo "<th>Time visitante</th>";
    echo "<th>Placar visitante</th>";
    echo "<th>Quem passa (se empate)</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";

    foreach ($rowsJogosMM as $row) {
        $fase = (string)($row['fase'] ?? '');
        $golsCasa = apostas_export_int_or_null($row['gols_casa'] ?? null);
        $golsFora = apostas_export_int_or_null($row['gols_fora'] ?? null);
        $passaEmpate = '';
        if ($golsCasa !== null && $golsFora !== null && $golsCasa === $golsFora) {
            $passaEmpate = trim((string)($row['passa_nome'] ?? ''));
        }

        echo "<tr>";
        echo "<td>" . apostas_export_strh($faseLabel[$fase] ?? $fase) . "</td>";
        echo "<td>" . apostas_export_strh(apostas_export_format_datetime((string)($row['data_hora'] ?? ''))) . "</td>";
        echo "<td>" . apostas_export_strh((string)($row['codigo_fifa'] ?? '')) . "</td>";
        echo "<td>" . apostas_export_strh((string)($row['casa_nome'] ?? '')) . "</td>";
        echo "<td>" . apostas_export_strh($golsCasa === null ? '' : (string)$golsCasa) . "</td>";
        echo "<td>" . apostas_export_strh((string)($row['fora_nome'] ?? '')) . "</td>";
        echo "<td>" . apostas_export_strh($golsFora === null ? '' : (string)$golsFora) . "</td>";
        echo "<td>" . apostas_export_strh($passaEmpate) . "</td>";
        echo "</tr>";
    }

    echo "</tbody>";
    echo "</table>";
    echo "</body></html>";

    return (string)ob_get_clean();
}
