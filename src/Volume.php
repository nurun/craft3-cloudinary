<?php

namespace craft\cloudinary;

use Craft;
use craft\base\FlysystemVolume;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;

/**
 * Class Volume
 *
 * @property null|string $settingsHtml
 * @property string $rootUrl
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Volume extends FlysystemVolume
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Cloudinary';
    }

    // Properties
    // =========================================================================

    /**
     * @var string Path to the root of this sources local folder.
     */
    public $subfolder = '';

    /**
     * @var string Cloudinary API key
     */
    public $apiKey = '';

    /**
     * @var string Cloudinary API secret
     */
    public $apiSecret = '';

    /**
     * @var string Cloudinary cloud name to use
     */
    public $cloudName = '';

    /**
     * @var string Base path to prefix all assets
     */
    public $basePath = '';

    /**
     * @var bool Overwrite existing files on Cloudinary
     */
    public $overwrite = true;

    /**
     * @var bool Whether the Flysystem adapter expects folder names to have trailing slashes
     */
    protected $foldersHaveTrailingSlashes = false;

    /**
     * @inheritdoc
     * Always disable asserts by default
     */
    protected function filesystem(array $config = ['disable_asserts' => true]): Filesystem
    {
        return new Filesystem($this->adapter(), new Config($config));
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        $rules = parent::rules();
        $rules[] = [['cloudName', 'apiKey', 'apiSecret'], 'required'];

        return $rules;
    }

    /**
     * @inheritdoc
     *
     * @return string|null
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate(
            'cloudinary/volumeSettings',
            [
                'volume' => $this
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function getRootUrl()
    {
        if (!$this->hasUrls) {
            return false;
        }

        return rtrim(rtrim(Craft::parseEnv($this->url), '/') . '/' . $this->subfolder, '/') . '/';
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return CloudinaryAdapter
     */
    protected function createAdapter(): CloudinaryAdapter
    {
        $config = [
            'api_key' => Craft::parseEnv($this->apiKey),
            'api_secret' => Craft::parseEnv($this->apiSecret),
            'cloud_name' => Craft::parseEnv($this->cloudName),
        ];

        $basePath = Craft::parseEnv($this->basePath);
        if (substr($basePath, 0, 1) === '$' || substr($basePath, 0, 1) === '@') {
            $basePath = '';
        }
        return new CloudinaryAdapter($config, $basePath);
    }
}
