<?php
/**
 * Created by PhpStorm.
 * User: Xingshun <250915790@qq.com>
 * Date: 2019/9/27
 * Time: 20:05
 */

namespace XsKit\Swoft\Rpc;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;

/**
 * RPC 服务客户端
 * Class Client
 * @package App\Services\Rpc
 * @see swoft rpc
 * @since 2.0
 */
class Client
{
    private $config;

    private $connection;

    private static $instance = null;

    private $name;

    private $method;

    private $param;

    private $id;

    private $ext = [];

    const RPC_EOL = "\r\n\r\n";

    private $poolConnection = [];

    private $errorCode = 0;
    private $errorMessage;

    private $error = false;

    private $result;

    private $resultRaw;

    public function __construct()
    {
        $this->config = Config::get('swoft_rpc_client');
    }

    /**
     * 使用 RPC 服务
     * @param $name
     * @param $method
     * @param array $param
     * @param array $ext
     * @return $this
     * @throws \Exception
     */
    public static function usage($name, $method, $param = [], $ext = [])
    {
        if (self::$instance == null) {
            self::$instance = new self();
        }

        return self::$instance->service($name)->method($method)->param($param)->ext($ext);
    }

    /**
     * 生成请求ID
     * @return string
     */
    private function generatorRequestId()
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Exception $e) {
            return '';
        }

    }

    /**
     * 执行调用
     * @return $this
     * @throws \Throwable
     */
    public function call()
    {
        try {
            $this->resultRaw = $this->request();
            $this->error = Arr::has($this->resultRaw, 'error');
            $this->errorMessage = Arr::get($this->resultRaw, 'error.message');
            $this->errorCode = Arr::get($this->resultRaw, 'error.code');
            $this->result = Arr::get($this->resultRaw, 'result');
        } catch (\Exception $e) {
            $this->result = null;
            $this->errorCode = $e->getCode();
            $this->errorMessage = $e->getMessage();
            $this->error = true;
        } finally {
            return $this;
        }

    }

    /**
     * 获取调用成果
     * @return mixed
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * 获取调用成果，如果有异常或失败则抛出
     */
    public function getResultOrFail()
    {
        if ($this->isSuccess()) {
            return $this->getResult();
        }

        throw new \RuntimeException($this->getErrorMessage(), $this->getErrorCode());
    }

    /**
     * 获取原数据
     * @return array
     */
    public function getRaw()
    {
        return $this->resultRaw;
    }

    /**
     * 是否调用成功
     * @return bool
     */
    public function isSuccess()
    {
        return !$this->error;
    }

    /**
     * 获取请求ID
     * @return string
     */
    public function getRequestId()
    {
        return $this->id;
    }

    /**
     * 返回 错误信息
     * @return mixed
     */
    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function service($name)
    {
        $this->name = $name;
        return $this;
    }

    public function method($name)
    {
        $this->method = $name;
        return $this;
    }

    public function param($param)
    {
        $this->param = $param;
        return $this;
    }

    /**
     * 设置请求ID
     * @param $id
     * @return $this
     */
    public function setRequestId($id)
    {
        $this->id = $id;
        return $this;
    }

    public function ext($value)
    {
        $this->ext = $value;
        return $this;
    }

    /**
     * 设置连接
     * @param $name
     * @return $this
     */
    public function setConnection($name)
    {
        $this->connection = $name;
        return $this;
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function getVersion(): string
    {
        return Arr::get($this->getServices(), $this->name . '.version', '1.0');
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function getHost(): string
    {
        $conn = $this->getConnectionInfo();
        return Arr::get($conn, 'host') . ':' . Arr::get($conn, 'port');
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function getClass(): string
    {
        $class = Arr::get($this->getServices(), $this->name . '.class');
        if (empty($class)) {
            throw new \Exception('rpc service [' . $this->name . '] class not found');
        }
        return $class;
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    private function getConnectionInfo()
    {
        $this->connection = $this->connection ?: Arr::get($this->getServices(), $this->name . '.connection', 'default');

        $conn = Arr::get($this->config, 'connection.' . $this->connection);
        if (empty($conn)) {
            throw new \Exception('connection information not found');
        }
        return $conn;
    }

    /**
     * 获取服务配置
     * @return array
     */
    private function getServices(): array
    {
        return Arr::get($this->config, 'services', []);
    }

    /**
     * 返回 连接超时时间
     * @return int
     * @throws \Exception
     */
    private function getTimeout(): int
    {
        return Arr::get($this->getConnectionInfo(), 'setting.timeout', 3);
    }

    private function getWriteTimeout(): int
    {
        return Arr::get($this->getConnectionInfo(), 'setting.write_timeout', 10);
    }

    /**
     * @return array
     * @throws \Throwable
     */
    private function request(): array
    {
        $host = trim($this->getHost());
        try {
            if (isset($this->poolConnection[$host])) {
                $fp = $this->poolConnection[$host];
            } else {
                $fp = $this->poolConnection[$host] = stream_socket_client($this->getHost(), $errNo, $errStr, $this->getTimeout());
            }

            if (!$fp) {
                throw new \Exception(sprintf("stream_socket_client fail errno=%s errstr=%s", $errNo, $errStr));
            }

            $req = [
                "jsonrpc" => '2.0',
                "method" => sprintf("%s::%s::%s", $this->getVersion(), $this->getClass(), $this->method),
                'params' => $this->param,
                'id' => $this->id ?: $this->generatorRequestId(),
                'ext' => $this->ext,
            ];

            $data = json_encode($req) . self::RPC_EOL;
            fwrite($fp, $data);

            $result = '';
            // 记录开始时间,判断是否超时，避免 feof() 陷入无限循环
            $start = microtime(true);
            while (!feof($fp) && $this->checkTimeout($start)) {
                $tmp = stream_socket_recvfrom($fp, 1024);
                $start = microtime(true);

                if ($pos = strpos($tmp, self::RPC_EOL)) {
                    $result .= substr($tmp, 0, $pos);
                    break;
                } else {
                    $result .= $tmp;
                }
            }

            return json_decode($result, true);
        } catch (\Throwable $e) {
            fclose($this->poolConnection[$host]);
            unset($this->poolConnection[$host]);
            throw $e;
        }
    }

    /**
     * @param $time
     * @return bool
     */
    private function checkTimeout($time)
    {
        $res = (microtime(true) - $time) < $this->getWriteTimeout();
        if (!$res) {
            // 超时
            trigger_error('RPC invoke timeout');
        }
        return $res;
    }


    public function __destruct()
    {
        foreach ($this->poolConnection as $fp) {
            fclose($fp);
        }
    }
}