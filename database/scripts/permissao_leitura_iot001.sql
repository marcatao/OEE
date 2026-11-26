-- Permissão de LEITURA do portal OEE na tabela de leituras do IoT (MQTT.dbo.IOT001), usada pelo gráfico
-- "Ciclos da máquina" do Dashboard por máquina (App\Services\CiclosIot). Só SELECT: o portal nunca grava na IOT001.
-- Rodar como sysadmin (ou dono do banco MQTT). Troque [oee_app] pelo login do portal (DB_USERNAME do .env).
-- Idempotente: pode rodar mais de uma vez.
USE [MQTT];
GO
IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = N'oee_app')
    CREATE USER [oee_app] FOR LOGIN [oee_app];
GO
GRANT SELECT ON [dbo].[IOT001] TO [oee_app];
GO
