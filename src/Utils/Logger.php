<?php
namespace Mercotruck\Utils;

/**
 * Logger simple para auditoría de syncs
 * Escribe en archivo y devuelve estructuras para respuestas
 */
class Logger
{
    private string $logDir;
    private string $logFile;

    public function __construct(string $logDir = __DIR__ . '/../../logs')
    {
        $this->logDir = $logDir;

        // Crear directorio si no existe
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $this->logFile = $logDir . '/sync_' . date('Y-m-d') . '.log';
    }

    /**
     * Log de evento
     */
    public function log(string $level, string $endpoint, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $entry = "[$timestamp] [$level] [$endpoint] $message $contextStr\n";

        file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log de inicio de sync
     */
    public function logSyncStart(string $endpoint, array $params = []): void
    {
        $this->log('INFO', $endpoint, 'Sync iniciado', $params);
    }

    /**
     * Log de éxito
     */
    public function logSyncSuccess(string $endpoint, array $summary): void
    {
        $this->log('INFO', $endpoint, 'Sync completado', $summary);
    }

    /**
     * Log de error
     */
    public function logSyncError(string $endpoint, string $error, array $context = []): void
    {
        $this->log('ERROR', $endpoint, $error, $context);
    }

    /**
     * Log de creación/actualización
     */
    public function logRecord(string $endpoint, string $action, string $model, int|string $id, array $data = []): void
    {
        $this->log('DEBUG', $endpoint, "$action en $model ID=$id", $data);
    }

    /**
     * Obtener contenido del log de hoy
     */
    public function getTodayLogs(): string
    {
        return file_exists($this->logFile) ? file_get_contents($this->logFile) : 'Sin logs hoy';
    }

    /**
     * Retorna el path del log file
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }
}
?>
