-- Estrutura de controle de saudacoes automaticas WhatsApp.
-- Uma saudacao por telefone e barbearia a cada 12 horas.
-- Esta migracao nao deve apagar dados existentes.

CREATE TABLE IF NOT EXISTS whatsapp_saudacoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    barbearia_id INT NOT NULL,
    whatsapp VARCHAR(20) NOT NULL,
    ultima_saudacao_em DATETIME DEFAULT NULL,

    status ENUM(
        'pendente',
        'enviando',
        'enviado',
        'falhou',
        'incerto'
    ) NOT NULL DEFAULT 'pendente',

    execucao_id VARCHAR(64) DEFAULT NULL,
    reservado_em DATETIME DEFAULT NULL,

    atualizado_em DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uk_saudacao_barbearia_whatsapp (
        barbearia_id,
        whatsapp
    ),

    CONSTRAINT fk_saudacao_barbearia
        FOREIGN KEY (barbearia_id)
        REFERENCES barbearias (id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;