<?php
/**
 * Logger.php - ファイルロガー
 */
class Logger
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
        $dir = dirname($logFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }

    public function info(string $msg): void  { $this->write('INFO',    $msg); }
    public function warning(string $msg): void { $this->write('WARNING', $msg); }
    public function error(string $msg): void { $this->write('ERROR',   $msg); }
    public function debug(string $msg): void { $this->write('DEBUG',   $msg); }

    private function write(string $level, string $msg): void
    {
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $msg);
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        echo $line; // cronログにも出力
    }
}
