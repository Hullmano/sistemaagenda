<?php
// helpers/agenda.php

function obterHorariosLivres($db, $barbearia_id, $data_desejada, $duracao_servico_minutos) {
    // 1. Descobrir o dia da semana (0 = Domingo, 6 = Sábado)
    $dia_semana = date('w', strtotime($data_desejada));

    // 2. Buscar o horário de funcionamento da barbearia para este dia
    $stmt = $db->prepare("
        SELECT hora_abertura, hora_fechamento, hora_almoco_inicio, hora_almoco_fim 
        FROM horarios_funcionamento 
        WHERE barbearia_id = ? AND dia_semana = ?
        LIMIT 1
    ");
    $stmt->execute([$barbearia_id, $dia_semana]);
    $funcionamento = $stmt->fetch();

    // Se a barbearia não abre nesse dia, retorna uma lista vazia
    if (!$funcionamento) {
        return [];
    }

    // 3. Buscar os agendamentos já existentes/ocupados para este dia (que não estejam cancelados)
    $stmt = $db->prepare("
        SELECT horario_inicio, horario_fim 
        FROM agendamentos 
        WHERE barbearia_id = ? AND data_agendamento = ? AND status != 'cancelado'
        ORDER BY horario_inicio ASC
    ");
    $stmt->execute([$barbearia_id, $data_desejada]);
    $agendamentos_ocupados = $stmt->fetchAll();

    // 4. Gerar a grade de horários possíveis do dia (de 30 em 30 minutos)
    $horarios_disponiveis = [];
    $atual = strtotime($funcionamento['hora_abertura']);
    $fim_expediente = strtotime($funcionamento['hora_fechamento']);
    $intervalo = 30 * 60; // 30 minutos em segundos

    // Horários de almoço convertidos para timestamp (se houver)
    $almoco_inicio = $funcionamento['hora_almoco_inicio'] ? strtotime($funcionamento['hora_almoco_inicio']) : null;
    $almoco_fim = $funcionamento['hora_almoco_fim'] ? strtotime($funcionamento['hora_almoco_fim']) : null;

    // Loop para testar cada slot de horário do dia
    while ($atual < $fim_expediente) {
        // Calcula o horário hipotético de término deste serviço
        $termino_servico = $atual + ($duracao_servico_minutos * 60);

        // Se o serviço ultrapassar o horário de fechamento da barbearia, encerra o loop
        if ($termino_servico > $fim_expediente) {
            break;
        }

        $colidiu = false;

        // Regra A: Verificar colisão com o horário de almoço fixo
        if ($almoco_inicio && $almoco_fim) {
            if (
                // Caso 1: Se o atendimento começa dentro do almoço
                ($atual >= $almoco_inicio && $atual < $almoco_fim) || 
                
                // Caso 2: Se o atendimento termina dentro do almoço
                ($termino_servico > $almoco_inicio && $termino_servico <= $almoco_fim) ||
                
                // Caso 3 (O BUG!): Se o atendimento começa ANTES e termina DEPOIS do almoço (engloba o almoço)
                ($atual <= $almoco_inicio && $termino_servico >= $almoco_fim)
            ) {
                $colidiu = true;
            }
        }


        // Regra B: Verificar colisão com agendamentos já existentes
        if (!$colidiu) {
            foreach ($agendamentos_ocupados as $agendamento) {
                $ocupado_inicio = strtotime($agendamento['horario_inicio']);
                $ocupado_fim = strtotime($agendamento['horario_fim']);

                // Valida sobreposição de horários
                if (($atual >= $ocupado_inicio && $atual < $ocupado_fim) || 
                    ($termino_servico > $ocupado_inicio && $termino_servico <= $ocupado_fim) ||
                    ($atual <= $ocupado_inicio && $termino_servico >= $ocupado_fim)) {
                    $colidiu = true;
                    break; // Sai do foreach, este horário está descartado
                }
            }
        }

        // Se passar por todas as regras sem colidir, o horário está LIVRE!
        if (!$colidiu) {
            $horarios_disponiveis[] = date('H:i', $atual);
        }

        // Avança para o próximo bloco de tempo da grade
        $atual += $intervalo;
    }

    return $horarios_disponiveis;
}
