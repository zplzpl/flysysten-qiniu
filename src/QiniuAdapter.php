<?php
/**
 * Created by PhpStorm.
 * User: frli
 * Date: 15-12-18
 * Time: 下午10:46
 */

namespace Skyling\Flysystem\Qiniu;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedReadingTrait;
use League\Flysystem\Adapter\Polyfill\StreamedWritingTrait;
use League\Flysystem\Config;
use Qiniu\Auth;
use Qiniu\Processing\Operation;
use Qiniu\Processing\PersistentFop;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;

class QiniuAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait, StreamedWritingTrait, StreamedReadingTrait;

    private $accessKey = null;
    private $secretKey = null;
    private $bucket = null;
    private $domain = null;

    private $auth = null;
    private $token = null;
    private $operation = null;
    private $uploadManager = null;
    private $bucketManager = null;

    public function __construct($accessKey, $secretKey, $bucket, $domain)
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->bucket = $bucket;
        $this->domain = $domain;
        $this->setPathPrefix($this->domain);

        $this->bucketManager = new BucketManager($this->getAuth());
        $this->uploadManager = new UploadManager();
    }

    /**
     * 获取七牛Auth对象
     * @return Auth
     */
    private function getAuth()
    {
        if ($this->auth == null) {
            $this->auth = new Auth($this->accessKey, $this->secretKey);
        }
        return $this->auth;
    }

    /**
     * 获取上传TOKEN
     * @param string $path 文件名称
     * @return string
     */
    private function getToken($path = null)
    {
        if ($this->token == null ) {
            $this->token = $this->setToken($path);
        }
        return $this->token;
    }

    /**
     * 获取BucketManger 对象
     * @return BucketManager
     */
    private function getBucketManager()
    {
        if ($this->bucketManager == null) {
            $this->bucketManager = new BucketManager($this->getAuth());
        }
        return $this->bucketManager;
    }

    /**
     * @return UploadManager
     */
    private function getUploadManager()
    {
        if ($this->uploadManager == null) {
            $this->uploadManager = new UploadManager();
        }
        return $this->uploadManager;
    }

    /**
     * @return Operation
     */
    private function getOperation()
    {
        if ($this->operation == null) {
            $this->operation = new Operation($this->domain);
        }
        return $this->operation;
    }

    private function logQiniuError(Error $error)
    {
        //http://developer.qiniu.com/docs/v6/api/reference/codes.html
        $notLogCode = [612];

        /*if (!in_array($error->code(), $notLogCode)) {
            \Log::error('Qiniu: ' . $error->code() . ' ' . $error->message());
        }*/
    }

    /**
     * 设置token
     * @param null $path
     * @param int $expires
     * @param null $policy
     * @param bool|true $strictPolicy
     * @return string
     */
    public function setToken($path = null, $expires = 3600, $policy = null, $strictPolicy = true)
    {
        $auth = $this->getAuth();
        $this->token = $auth->uploadToken($this->bucket, $path, $expires, $policy, $strictPolicy);
        return $this->token;
    }

    /**
     * 返回处理
     * @param $ret
     * @param $error
     * @param null $key
     * @return bool
     */
    private function returnDeal($ret, $error, $key = null)
    {
        if ($error !== null) {
            $this->logQiniuError($error);
            return false;
        }
        if ($key !== null && isset($ret[$key])) {
            return $ret[$key];
        }
        return $ret;
    }

    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * 重命名
     * @param string $path
     * @param string $newpath
     * @return bool
     */
    public function rename($path, $newpath)
    {
        $bucketMgr = $this->getBucketManager();
        list($ret, $error) = $bucketMgr->move($this->bucket, $path, $this->bucket, $newpath);

        return $this->returnDeal($ret, $error);
    }

    public function write($path, $contents, Config $config, $isPutFile = false)
    {
        $token = $this->getToken($path);
        $params = $config->get('params', null);
        $mime = $config->get('mime', 'application/octet-stream');
        $checkCrc = $config->get('checkCrc', false);

        $uploadMgr = $this->getUploadManager();
        if ($isPutFile) {
            list($ret, $error) = $uploadMgr->putFile($token, $path, $contents, $params, $mime, $checkCrc);
        } else {
            list($ret, $error) = $uploadMgr->put($token, $path, $contents, $params, $mime, $checkCrc);
        }

        return $this->returnDeal($ret, $error);
    }

    public function copy($path, $newpath)
    {
        $bucketMgr = $this->getBucketManager();

        list($ret, $error) = $bucketMgr->copy($this->bucket, $path, $this->bucket, $newpath);
        return $this->returnDeal($ret, $error);
    }

    public function delete($path)
    {
        $bucketMgr = $this->getBucketManager();

        list($ret, $error) = $bucketMgr->delete($this->bucket, $path);

        $this->returnDeal($ret, $error);

    }

    public function deleteDir($dirname)
    {
        $files = $this->listContents($dirname);
        foreach ($files as $file) {
            $this->delete($file['path']);
        }
        return true;
    }


    public function createDir($dirname, Config $config)
    {
        return ['path' => $dirname];
    }

    public function has($path)
    {
        $meta = $this->getMetadata($path);
        if ($meta) {
            return true;
        }
        return false;
    }

    /**
     * @param string $path
     *
     * @return resource
     */
    public function read($path)
    {
        $location = $this->applyPathPrefix($path);
        return array('contents' => file_get_contents($location));
    }

    public function listContents($directory = '', $recursive = false)
    {
        $bucketMgr = $this->getBucketManager();

        list($items, $marker, $error) = $bucketMgr->listFiles($this->bucket, $directory);
        if ($error !== null) {
            $this->logQiniuError($error);

            return array();
        } else {
            $contents = array();
            foreach ($items as $item) {
                $normalized = ['type' => 'file', 'path' => $item['key'], 'timestamp' => $item['putTime']];

                if ($normalized['type'] === 'file') {
                    $normalized['size'] = $item['fsize'];
                }

                array_push($contents, $normalized);
            }
            return $contents;
        }
    }

    public function getMetadata($path)
    {
        $bucketMgr = $this->getBucketManager();

        list($ret, $error) = $bucketMgr->stat($this->bucket, $path);
        return $this->returnDeal($ret, $error);
    }

    public function getSize($path)
    {
        $stat = $this->getMetadata($path);
        if ($stat) {
            return array('size' => $stat['fsize']);
        }
        return false;
    }

    public function getMimetype($path)
    {
        $stat = $this->getMetadata($path);
        if ($stat) {
            return array('mimetype' => $stat['mimeType']);
        }
        return false;
    }

    public function getTimestamp($path)
    {
        $stat = $this->getMetadata($path);
        if ($stat) {
            return array('timestamp' => $stat['putTime']);
        }
        return false;
    }

    /**
     * 获取私有下载链接
     * @param $path 文件名称
     * @param int $expires 有效时间
     * @return string
     */
    public function privateDownloadUrl($path, $expires = 3600)
    {
        $auth = $this->getAuth();
        $location = $this->applyPathPrefix($path);
        $authUrl = $auth->privateDownloadUrl($location, $expires);

        return $authUrl;
    }

    /**
     * 对资源文件进行异步持久化处理
     * @param string $path 待处理的源文件
     * @param string|array $fops 待处理的pfop操作，多个pfop操作以array的形式传入。
     * @return bool
     */
    public function persistentFop($path = null, $fops = null)
    {
        $auth = $this->getAuth();

        $pfop = new PersistentFop($auth, $this->bucket);
        list($id, $error) = $pfop->execute($path, $fops);
        return $this->returnDeal($id, $error);
    }

    /**
     * 获取持久化文件状态
     * @param $id
     * @return array
     */
    public function persistentStatus($id)
    {
        return PersistentFop::status($id);
    }

    /**
     * 从指定URL抓取资源，并将该资源存储到指定空间中
     * @param $url
     * @param $key
     * @return bool
     */
    public function fetch($url, $key)
    {
        $bucketMgr = $this->getBucketManager();
        list($ret, $error) = $bucketMgr->fetch($url, $this->bucket, $key);
        return $this->returnDeal($ret, $error, 'key');
    }

    /**
     * 获取下载链接
     * @param null $path
     * @return string
     */
    public function downloadUrl($path = null)
    {
        $location = $this->applyPathPrefix($path);
        return $location;
    }

    /**
     * 获取图片信息
     * @param null $path
     * @return bool
     */
    public function imageInfo($path = null)
    {
        $operation = $this->getOperation();
        list($ret, $error) = $operation->execute($path, 'imageInfo');
        return $this->returnDeal($ret, $error);
    }

    /**
     * 获取图片Exif信息
     * @param null $path
     * @return bool
     */
    public function imageExif($path = null)
    {
        $operate = $this->getOperation();
        list($ret, $error) = $operate->execute($path, 'exif');
        return $this->returnDeal($ret, $error);
    }

    /**
     * 获取图片预览地址
     * @param null $path
     * @param null $ops
     * @return string
     */
    public function imagePreviewUrl($path = null, $ops = null)
    {
        $operate = $this->getOperation();
        $url = $operate->buildUrl($path, $ops);
        return $url;
    }
}

/*  End of file QiniuAdapter.php*/