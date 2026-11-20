
<?php
// api/adicionar_servico_comanda.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

function responderServico(int $codigo, array $dados): void
{
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

// Somente POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderServico(405, ['erro' => 'Método não permitido.']);
}

// Autenticação.
$usuario = usuarioLogado();

if (!$usuario) {
    responderServico(401, ['erro' => 'Sessão expirada.']);
}

// Proteção CSRF.
if (!validarTokenCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    responderServico(403, [
        'erro' => 'Token de segurança inválido.'
    ]);
}

// Dados enviados pelo navegador.
$dados = json_decode(file_get_contents('php://input'), true);

if (!is_array($dados)) {
    responderServico(400, ['erro' => 'JSON inválido.']);
}

$comandaId = filter_var(
    $dados['comanda_id'] ?? null,
    FILTER_VALIDATE_INT
);

$servicoId = filter_var(
    $dados['servico_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$comandaId || !$servicoId ||
    $comandaId <= 0 || $servicoId <= 0) {
    responderServico(400, [
        'erro' => 'Comanda ou serviço inválido.'
    ]);
}

try {
    $db = Database::getConnection();
    $db->beginTransaction();

    $barbeariaId = (int)$usuario['barbearia_id'];
    $usuarioId = (int)$usuario['id'];

    // Mantém a ordem de bloqueios das APIs financeiras.
    $stmt = $db->prepare("
        SELECT id
        FROM barbearias
        WHERE id = ?
        FOR UPDATE
    ");

    $stmt->execute([$barbeariaId]);

    if (!$stmt->fetchColumn()) {
        throw new RuntimeException(
            'Barbearia não encontrada.', 404
        );
    }

    // Bloqueia a comanda e verifica o atendimento.
    $stmt = $db->prepare("
        SELECT
            c.id,
            c.barbearia_id,
            c.status,
            c.valor_total,
            a.usuario_id,
            a.status AS status_agendamento
        FROM comandas c
        JOIN agendamentos a
            ON a.id = c.agendamento_id
           AND a.barbearia_id = c.barbearia_id
        WHERE c.id = ?
          AND c.barbearia_id = ?
        FOR UPDATE
    ");

    $stmt->execute([$comandaId, $barbeariaId]);

    $comanda = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$comanda) {
        throw new RuntimeException(
            'Comanda não encontrada.', 404
        );
    }

    if (!podeGerenciarAgendamento($usuario, $comanda)) {
        throw new RuntimeException(
            'Acesso não autorizado.', 403
        );
    }

    if ($comanda['status'] !== 'aberta') {
        throw new RuntimeException(
            'Somente comandas abertas podem receber serviços.',
            409
        );
    }

    if ($comanda['status_agendamento'] !== 'concluido') {
        throw new RuntimeException(
            'Conclua o atendimento antes de adicionar serviços.',
            409
        );
    }

    // Consulta o serviço diretamente no banco.
    $stmt = $db->prepare("
        SELECT id, nome, preco
        FROM servicos
        WHERE id = ?
          AND barbearia_id = ?
          AND ativo = 1
        FOR UPDATE
    ");

    $stmt->execute([$servicoId, $barbeariaId]);

    $servico = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$servico) {
        throw new RuntimeException(
            'Serviço não encontrado ou inativo.', 404
        );
    }

    if ((float)$servico['preco'] <= 0) {
        throw new RuntimeException(
            'O serviço precisa ter valor maior que zero.',
            409
        );
    }

    // Nesta versão, um mesmo serviço só pode
    // aparecer uma vez na comanda.
    $stmt = $db->prepare("
        SELECT id
        FROM comanda_itens
        WHERE comanda_id = ?
          AND tipo = 'servico'
          AND referencia_id = ?
        LIMIT 1
    ");

    $stmt->execute([$comandaId, $servicoId]);

    if ($stmt->fetchColumn()) {
        throw new RuntimeException(
            'Este serviço já está incluído na comanda.',
            409
        );
    }

    // Registra o novo item com quantidade 1.
    $stmt = $db->prepare("
        INSERT INTO comanda_itens
            (
                comanda_id,
                tipo,
                referencia_id,
                descricao,
                quantidade,
                valor_unitario,
                valor_total
            )
        VALUES (?, 'servico', ?, ?, 1.00, ?, ?)
    ");

    $stmt->execute([
        $comandaId,
        $servicoId,
        $servico['nome'],
        $servico['preco'],
        $servico['preco']
    ]);

    $itemId = (int)$db->lastInsertId();

    // Recalcula o total a partir dos itens.
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(valor_total), 0)
        FROM comanda_itens
        WHERE comanda_id = ?
    ");

    $stmt->execute([$comandaId]);
    $novoTotal = (string)$stmt->fetchColumn();

    $stmt = $db->prepare("
        UPDATE comandas
        SET valor_total = ?
        WHERE id = ?
          AND barbearia_id = ?
          AND status = 'aberta'
    ");

    $stmt->execute([
        $novoTotal,
        $comandaId,
        $barbeariaId
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Não foi possível atualizar a comanda.',
            409
        );
    }

    // Auditoria da inclusão.
    $stmt = $db->prepare("
        INSERT INTO comanda_movimentacoes
            (comanda_id, usuario_id, acao, descricao, dados_novos)
        VALUES (?, ?, 'inclusao_item', ?, ?)
    ");

    $stmt->execute([
        $comandaId,
        $usuarioId,
        'Serviço adicional incluído na comanda.',
        json_encode([
            'item_id' => $itemId,
            'servico_id' => $servicoId,
            'descricao' => $servico['nome'],
            'valor' => $servico['preco'],
            'novo_total' => $novoTotal
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    ]);

    $db->commit();

    responderServico(200, [
        'sucesso' => true,
        'mensagem' => 'Serviço adicionado com sucesso.',
        'comanda_id' => $comandaId,
        'item_id' => $itemId,
        'valor_total' => $novoTotal
    ]);

} catch (Throwable $e) {

    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    $codigo = (int)$e->getCode();

    if ($e instanceof RuntimeException &&
        in_array($codigo, [403, 404, 409], true)) {
        responderServico($codigo, [
            'erro' => $e->getMessage()
        ]);
    }

    error_log(
        'Erro ao adicionar serviço: ' . $e->getMessage()
    );

    responderServico(500, [
        'erro' => 'Erro interno ao adicionar serviço.'
    ]);
}
