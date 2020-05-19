<?php

/**
 * Original adapter at https://github.com/carlosocarvalho/flysystem-cloudinary
 */

namespace craft\cloudinary;

use Exception;
use Cloudinary as ClDriver;
use Cloudinary\Api;
use Cloudinary\Uploader;
use League\Flysystem\Config;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;

/**
 *
 */
class CloudinaryAdapter implements AdapterInterface
{
    /**
     * @var Api
     */
    protected $api;

    /**
     * Cloudinary does not support visibility - all is public
     */
    use NotSupportingVisibilityTrait;

    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_RAW = 'raw';

    /**
     * Constructor
     * Sets configuration, and dependency Cloudinary Api.
     * @param array $options Cloudinary configuration
     */
    public function __construct(array $options)
    {
        ClDriver::config($options);
        $this->api = new Api;
    }

    /**
     * Write a new file.
     * Create temporary stream with content.
     * Pass to writeStream.
     * @param string $path
     * @param string $contents
     * @param Config $options Config object
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $options)
    {
        // 1. Save to temporary local file -- it will be destroyed automatically
        $tempFile = tmpfile();
        fwrite($tempFile, $contents);
        // 2. Use Cloudinary to send
        return $this->writeStream($path, $tempFile, $options);
    }

    /**
     * Write a new file using a stream.
     * @param string $path
     * @param resource $resource
     * @param Config $options Config object
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $options)
    {
        $public_id = $options->has('public_id') ?
            $options->get('public_id') : $this->removeExtension($path);

        $resourceMetadata = stream_get_meta_data($resource);
        return Uploader::upload(
            $resourceMetadata['uri'],
            [
                'public_id' => $public_id,
                'resource_type' => 'auto',
                'unique_filename' => false,
                'overwrite' => true,
                'invalidate' => true
            ]
        );
    }

    /**
     * Update a file.
     * Cloudinary has no specific update method. Overwrite instead.
     * @param string $path
     * @param string $contents
     * @param Config $options Config object
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $options)
    {
        return $this->write($path, $contents, $options);
    }

    /**
     * Update a file using a stream.
     * Cloudinary has no specific update method. Overwrite instead.
     * @param string $path
     * @param resource $resource
     * @param Config $options Config object
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $options)
    {
        return $this->writeStream($path, $resource, $options);
    }

    /**
     * Rename a file.
     * Paths without extensions.
     * @param string $path
     * @param string $newpath
     * @return bool
     */
    public function rename($path, $newpath)
    {
        $pathInfo = pathinfo($path);
        if ($pathInfo['dirname'] !== '.') {
            $pathRemote = $pathInfo['dirname'] . '/' . $pathInfo['filename'];
        } else {
            $pathRemote = $pathInfo['filename'];
        }
        $newPathInfo = pathinfo($newpath);
        if ($newPathInfo['dirname'] !== '.') {
            $newPathRemote = $newPathInfo['dirname'] . '/' . $newPathInfo['filename'];
        } else {
            $newPathRemote = $newPathInfo['filename'];
        }
        $result = Uploader::rename(
            $pathRemote,
            $newPathRemote,
            [
                'resource_type' => $this->getResourceType($path),
                'overwrite' => true,
                'invalidate' => true
            ]
        );
        $result_filename = pathinfo($result['public_id'], PATHINFO_FILENAME);
        return $result_filename === $newPathInfo['filename'];
    }

    /**
     * Copy a file.
     * Copy content from existing url.
     * @param string $path
     * @param string $newpath
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $newpath = $this->removeExtension($newpath);
        $result = Uploader::upload(
            $this->getUrl($path),
            [
                'public_id' => $newpath,
                'resource_type' => 'auto',
                'unique_filename' => false,
                'overwrite' => true,
                'invalidate' => true
            ]
        );
        return is_array($result) ? $result['public_id'] === $newpath : false;
    }

    /**
     * Delete a file.
     * @param string $path
     * @return bool
     */
    public function delete($path)
    {
        $result = Uploader::destroy(
            $this->removeExtension($path),
            [
                'resource_type' => $this->getResourceType($path),
                'invalidate' => true
            ]
        );
        return is_array($result) ? $result['result'] === 'ok' : false;
    }

    /**
     * Delete a directory.
     * Delete Files using directory as a prefix.
     * @param string $dirname
     * @return bool
     * @throws Api\GeneralError
     */
    public function deleteDir($dirname)
    {
        $this->api->delete_resources_by_prefix($dirname);
        return true;
    }

    /**
     * Create a directory.
     * Cloudinary does not realy embrace the concept of "directories".
     * Those are more like a part of a name / public_id.
     * Just keep swimming.
     * @param string $dirname directory name
     * @param Config $options
     * @return array|false
     */
    public function createDir($dirname, Config $options)
    {
        return ['path' => $dirname];
    }

    /**
     * Check whether a file exists.
     * Using url to check response headers.
     * @param string $path
     * @return array|bool|null
     */
    public function has($path)
    {
        return substr(get_headers($this->getUrl($path))[0], -6) === '200 OK';
    }

    /**
     * Read a file.
     * @param string $path
     * @return array|false
     */
    public function read($path)
    {
        $contents = file_get_contents($this->getUrl($path));
        return compact('contents', 'path');
    }

    /**
     * Read a file as a stream.
     * @param string $path
     * @return array|false
     */
    public function readStream($path)
    {
        $stream = fopen($this->getUrl($path), 'rb');
        return compact('stream', 'path');
    }

    /**
     * List contents of a directory.
     * @param string $directory
     * @param bool $hasRecursive
     * @return array
     * @throws Api\GeneralError
     */
    public function listContents($directory = '', $hasRecursive = false)
    {
        $resources = [];

        // get resources array
        $response = null;
        do {
            $response = (array)$this->api->resources(
                [
                    'type' => 'upload',
                    'prefix' => $directory,
                    'max_results' => 500,
                    'next_cursor' => $response['next_cursor'] ?? null,
                ]
            );
            $resources = array_merge($resources, $response['resources']);
        } while (array_key_exists('next_cursor', $response));

        // parse resourses
        foreach ($resources as $i => $resource) {
            $resources[$i] = $this->prepareResourceMetadata($resource);
        }
        return $resources;
    }

    /**
     * Get all the meta data of a file or directory.
     * @param string $path
     * @return array|false
     * @throws Api\GeneralError
     */
    public function getMetadata($path)
    {
        return $this->prepareResourceMetadata($this->getResource($path));
    }

    /**
     * Get all the meta data of a file or directory.
     * @param string $path
     * @return array|false
     * @throws Api\GeneralError
     */
    public function getSize($path)
    {
        return $this->prepareSize($this->getResource($path));
    }

    /**
     * Get the mimetype of a file.
     * Actually I don't think cloudinary supports mimetypes.
     * Or I am just stupid and cannot find it.
     * This is an ugly hack.
     * @param string $path
     * @return array|false
     * @throws Api\GeneralError
     */
    public function getMimetype($path)
    {
        return $this->prepareMimetype($this->getResource($path));
    }

    /**
     * Get the timestamp of a file.
     * @param string $path
     * @return array|false
     * @throws Api\GeneralError
     */
    public function getTimestamp($path)
    {
        return $this->prepareTimestamp($this->getResource($path));
    }

    /**
     * Get Resource data
     * @param string $path
     * @return array
     * @throws Api\GeneralError
     */
    protected function getResource($path)
    {
        return (array)$this->api->resource(
            $this->removeExtension($path),
            ['resource_type' => $this->getResourceType($path)]
        );
    }

    /**
     * Prepare apropriate metadata for resource metadata given from cloudinary.
     * @param array $resource
     * @return array
     */
    protected function prepareResourceMetadata($resource)
    {
        $resource['type'] = 'file';
        $resource['path'] = $resource['public_id'];
        $resource = array_merge($resource, $this->prepareSize($resource));
        $resource = array_merge($resource, $this->prepareTimestamp($resource));
        $resource = array_merge($resource, $this->prepareMimetype($resource));
        return $resource;
    }

    /**
     * prepare timestpamp response
     * @param array $resource
     * @return array
     */
    protected function prepareMimetype($resource)
    {
        // hack
        $mimetype = $resource['resource_type'] . '/' . $resource['format'];
        $mimetype = str_replace('jpg', 'jpeg', $mimetype); // hack to a hack
        return compact('mimetype');
    }

    /**
     * prepare timestpamp response
     * @param array $resource
     * @return array
     */
    protected function prepareTimestamp($resource)
    {
        $timestamp = strtotime($resource['created_at']);
        return compact('timestamp');
    }

    /**
     * prepare size response
     * @param array $resource
     * @return array
     */
    protected function prepareSize($resource)
    {
        $size = $resource['bytes'];
        return compact('size');
    }

    /**
     * @param $path
     * @return mixed|string
     */
    private function getUrl($path)
    {
        try {
            $result = $this->getResource($path);
            return $result['secure_url'];
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * @param $path
     * @return string
     */
    private function getResourceType($path)
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if (in_array($ext, $this->getSupportedImageExtensions(), true)) {
            return self::TYPE_IMAGE;
        }
        if (in_array($ext, $this->getSupportedVideoExtensions(), true)) {
            return self::TYPE_VIDEO;
        }
        return self::TYPE_RAW;
    }

    /**
     * @param string $path
     * @return mixed|string
     */
    private function removeExtension(string $path)
    {
        $type = $this->getResourceType($path);

        if ($type === self::TYPE_RAW) {
            return $path;
        }

        $pathInfo = pathinfo($path);

        return $pathInfo['dirname'] !== '.'
            ? implode(
                '/',
                [
                    $pathInfo['dirname'],
                    $pathInfo['filename'],
                ]
            )
            : $pathInfo['filename'];
    }

    /**
     * @return string[]
     */
    private function getSupportedImageExtensions()
    {
        return [
            "ai",
            "gif",
            "webp",
            "bmp",
            "djvu",
            "ps",
            "ept",
            "eps",
            "eps3",
            "fbx",
            "flif",
            "gif",
            "gltf",
            "heif",
            "heic",
            "ico",
            "indd",
            "jpg",
            "jpe",
            "jpeg",
            "jp2",
            "wdp",
            "jxr",
            "hdp",
            "pdf",
            "png",
            "psd",
            "arw",
            "cr2",
            "svg",
            "tga",
            "tif",
            "tiff",
            "webp"
        ];
    }

    /**
     * @return string[]
     */
    private function getSupportedVideoExtensions()
    {
        return [
            "3g2",
            "3gp",
            "avi",
            "flv",
            "m3u8",
            "ts",
            "m2ts",
            "mts",
            "mov",
            "mkv",
            "mp4",
            "mpeg",
            "mpd",
            "mxf",
            "ogv",
            "webm",
            "wmv"
        ];
    }
}
