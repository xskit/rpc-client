<?php
/**
 * Created by PhpStorm.
 * User: Xingshun <250915790@qq.com>
 * Date: 2020/3/11
 * Time: 0:19
 */

namespace XsKit\Swoft\DataFormat;


use XsKit\RpcClient\Contract\ContextInterface;
use XsKit\RpcClient\Contract\DataFormatterInterface;

class SwoftDataFormat implements DataFormatterInterface
{

    /**
     * @var ContextInterface
     */
    protected $context;

    public function __construct(ContextInterface $context)
    {
        $this->context = $context;
    }

    /**
     * @param array $data [$path, $params, $id]
     * @return array
     */
    public function formatRequest($data)
    {
        [
            'method' => $method,
            'params' => $params,
            'id' => $id,
        ] = $data;
        return [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => $id,
            'ext' => $this->context->getData(),
        ];
    }

    /**
     * @param array $data [$id, $result]
     * @return array
     */
    public function formatResponse($data)
    {
        ['id' => $id, 'result' => $result] = $data;
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param array $data [$id, $code, $message, $exception]
     * @return array
     */
    public function formatErrorResponse($data)
    {
        [$id, $code, $message, $data] = $data;

        if (isset($data) && $data instanceof \Throwable) {
            $data = [
                'class' => get_class($data),
                'code' => $data->getCode(),
                'message' => $data->getMessage(),
            ];
        }
        return [
            'jsonrpc' => '2.0',
            'id' => $id ?? null,
            'error' => [
                'code' => $code,
                'message' => $message,
                'data' => $data,
            ],
        ];
    }
}