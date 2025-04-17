<?php

class CacheService
{
    private int $expiry;

    /**
     * Constructs a new CacheService instance with a specified expiry time.
     *
     * @param int $expiry The cache expiry time in seconds. Defaults to 300 seconds.
     */

    public function __construct(int $expiry = 300)
    {
        $this->expiry = $expiry;
    }

    /**
     * Retrieves a cached item from the file system.
     *
     * @param string $key The cache key to retrieve.
     * @return mixed The cached item if found, otherwise false.
     */
    public function get(string $key)
    {
        $file = $this->getFilePath($key);

        if (file_exists($file) && (time() - filemtime($file) < $this->expiry)) {
            return json_decode(file_get_contents($file), true);
        }

        return false;
    }

    /**
     * Sets a cache item with the given key and data.
     *
     * @param string $key The cache key to set.
     * @param mixed $data The data to store in the cache.
     */
    public function set(string $key, $data): void
    {
        $file = $this->getFilePath($key);
        file_put_contents($file, json_encode($data));
    }

    /**
     * Returns the file path for the given cache key.
     *
     * This function hashes the cache key with MD5 to generate a unique filename.
     * The filename is then saved in the system's temporary directory.
     *
     * @param string $key The cache key to generate a file path for.
     * @return string The cache file path.
     */
    private function getFilePath(string $key): string
    {
        return sys_get_temp_dir() . "/x10-agent-dashboard_" . md5($key) . ".cache";
    }
}
