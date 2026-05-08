ALTER TABLE usuarios
ADD COLUMN data_nascimento DATE NULL AFTER nome,
ADD KEY idx_usuarios_data_nascimento (data_nascimento);