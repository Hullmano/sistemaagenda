CREATE TABLE IF NOT EXISTS cliente_alteracoes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 barbearia_id INT NOT NULL,
 cliente_id INT NULL,
 whatsapp_informado VARCHAR(20) NOT NULL,
 nome_solicitado VARCHAR(120) NOT NULL,
 whatsapp_solicitado VARCHAR(20) NOT NULL,
 observacao VARCHAR(500) NOT NULL DEFAULT '',
 status ENUM('pendente','aprovada','rejeitada') NOT NULL DEFAULT 'pendente',
 criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 analisado_em TIMESTAMP NULL,
 INDEX idx_status (barbearia_id,status,criado_em),
 INDEX idx_limite (barbearia_id,whatsapp_informado,criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
