-- Migracao inicial do modulo financeiro do SaaS de barbearias
-- Destino: banco saas_barbearia (MySQL 8.0)
-- ATENCAO: revisar e efetuar backup atualizado antes de executar em producao.
-- Este script cria apenas a estrutura; nao copia registros do ambiente local.
-- Execute apenas se as cinco tabelas ainda nao existirem.

CREATE TABLE `comandas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `barbearia_id` int NOT NULL,
  `agendamento_id` int NOT NULL,
  `usuario_fechamento_id` int DEFAULT NULL,
  `status` enum('aberta','fechada','cancelada') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aberta',
  `valor_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fechado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_comanda_agendamento` (`agendamento_id`),
  KEY `idx_comanda_barbearia_status` (`barbearia_id`,`status`),
  KEY `fk_comanda_usuario` (`usuario_fechamento_id`),
  CONSTRAINT `fk_comanda_agendamento` FOREIGN KEY (`agendamento_id`) REFERENCES `agendamentos` (`id`),
  CONSTRAINT `fk_comanda_barbearia` FOREIGN KEY (`barbearia_id`) REFERENCES `barbearias` (`id`),
  CONSTRAINT `fk_comanda_usuario` FOREIGN KEY (`usuario_fechamento_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `comanda_itens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `comanda_id` int NOT NULL,
  `tipo` enum('servico','produto') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `referencia_id` int DEFAULT NULL,
  `descricao` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantidade` decimal(10,2) NOT NULL DEFAULT '1.00',
  `valor_unitario` decimal(10,2) NOT NULL DEFAULT '0.00',
  `valor_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comanda_itens_comanda` (`comanda_id`),
  CONSTRAINT `fk_comanda_itens_comanda` FOREIGN KEY (`comanda_id`) REFERENCES `comandas` (`id`),
  CONSTRAINT `chk_comanda_itens_quantidade` CHECK ((`quantidade` > 0)),
  CONSTRAINT `chk_comanda_itens_valores` CHECK (((`valor_unitario` >= 0) and (`valor_total` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pagamentos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `comanda_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `forma_pagamento` enum('pix','dinheiro','debito','credito','outro') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `pago_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `estornado_em` datetime DEFAULT NULL,
  `usuario_estorno_id` int DEFAULT NULL,
  `motivo_estorno` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pagamentos_comanda` (`comanda_id`),
  KEY `idx_pagamentos_data` (`pago_em`),
  KEY `fk_pagamentos_usuario` (`usuario_id`),
  CONSTRAINT `fk_pagamentos_comanda` FOREIGN KEY (`comanda_id`) REFERENCES `comandas` (`id`),
  CONSTRAINT `fk_pagamentos_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `chk_pagamentos_valor` CHECK ((`valor` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `comanda_movimentacoes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `comanda_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `acao` enum('abertura','inclusao_item','remocao_item','alteracao_valor','registro_pagamento','estorno_pagamento','fechamento','reabertura','correcao_pagamento') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dados_anteriores` json DEFAULT NULL,
  `dados_novos` json DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_movimentacoes_comanda` (`comanda_id`),
  KEY `idx_movimentacoes_usuario` (`usuario_id`),
  CONSTRAINT `fk_movimentacoes_comanda` FOREIGN KEY (`comanda_id`) REFERENCES `comandas` (`id`),
  CONSTRAINT `fk_movimentacoes_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `conferencias_caixa` (
  `id` int NOT NULL AUTO_INCREMENT,
  `barbearia_id` int NOT NULL,
  `usuario_responsavel_id` int NOT NULL,
  `usuario_conferencia_id` int NOT NULL,
  `data_referencia` date NOT NULL,
  `valor_registrado` decimal(10,2) NOT NULL DEFAULT '0.00',
  `ultimo_pagamento_id` int DEFAULT NULL,
  `valor_conferido` decimal(10,2) NOT NULL DEFAULT '0.00',
  `diferenca` decimal(10,2) NOT NULL DEFAULT '0.00',
  `observacao` varchar(255) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conferencia_responsavel_dia` (`barbearia_id`,`usuario_responsavel_id`,`data_referencia`),
  KEY `fk_conferencia_responsavel` (`usuario_responsavel_id`),
  KEY `fk_conferencia_usuario` (`usuario_conferencia_id`),
  KEY `idx_conferencia_data` (`barbearia_id`,`data_referencia`),
  CONSTRAINT `fk_conferencia_barbearia` FOREIGN KEY (`barbearia_id`) REFERENCES `barbearias` (`id`),
  CONSTRAINT `fk_conferencia_responsavel` FOREIGN KEY (`usuario_responsavel_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_conferencia_usuario` FOREIGN KEY (`usuario_conferencia_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
