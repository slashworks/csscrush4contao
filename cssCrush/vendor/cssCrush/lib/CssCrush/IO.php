<?php
/**
 *
 * Interface for writing files, retrieving files and checking caches
 *
 */
namespace CssCrush;

class IO
{
    public static function init()
    {
        $process = Crush::$process;
        $process->cacheFile = "{$process->output->dir}/.csscrush";
    }

    public static function getOutputDir()
    {
        $process = Crush::$process;
        $output_dir = $process->options->output_dir;

        return $output_dir ? $output_dir : $process->input->dir;
    }

    public static function testOutputDir()
    {
        $dir = Crush::$process->output->dir;
        $pathtest = true;

        if (! file_exists($dir)) {
            warning("[[CssCrush]] - Output directory '$dir' doesn't exist.");
            $pathtest = false;
        }
        elseif (! is_writable($dir)) {

            debug('Attempting to change permissions.');

            if (! @chmod($dir, 0755)) {
                warning("[[CssCrush]] - Output directory '$dir' is unwritable.");
                $pathtest = false;
            }
            else {
                debug('Permissions updated.');
            }
        }

        return $pathtest;
    }

    public static function getOutputFileName()
    {
        $process = Crush::$process;
        $options = $process->options;

        $output_basename = basename($process->input->filename, '.css');

        if (! empty($options->output_file)) {
            $output_basename = basename($options->output_file, '.css');
        }

        return "$output_basename.crush.css";
    }

    public static function getOutputUrl()
    {
        $process = Crush::$process;
        $options = $process->options;
        $filename = $process->output->filename;

        $url = $process->output->dirUrl . '/' . $filename;

        // Make URL relative if the input path was relative.
        $input_path = new Url($process->input->raw);
        if ($input_path->isRelative) {
            $url = Util::getLinkBetweenPaths(Crush::$config->scriptDir, $process->output->dir) . $filename;
        }

        // Optional query-string timestamp.
        if ($options->versioning !== false) {
            $url .= '?';
            if (isset($process->cacheData[$filename]['datem_sum'])) {
                $url .= $process->cacheData[$filename]['datem_sum'];
            }
            else {
                $url .= time();
            }
        }

        return $url;
    }

    public static function validateCache()
    {
        $process = Crush::$process;
        $config = Crush::$config;
        $options = $process->options;
        $input = $process->input;
        $output = $process->output;

        $filename = $output->filename;

        if (! file_exists($output->dir . '/' . $filename)) {
            debug('No file cached.');

            return false;
        }

        if (! isset($process->cacheData[$filename])) {
            debug('Cached file exists but is not registered.');

            return false;
        }

        $data =& $process->cacheData[$filename];

        // Make stack of file mtimes starting with the input file.
        $file_sums = array($input->mtime);
        foreach ($data['imports'] as $import_file) {

            // Check if this is docroot relative or input dir relative.
            $root = strpos($import_file, '/') === 0 ? $process->docRoot : $input->dir;
            $import_filepath = realpath($root) . "/$import_file";

            if (file_exists($import_filepath)) {
                $file_sums[] = filemtime($import_filepath);
            }
            else {
                // File has been moved, remove old file and skip to compile.
                debug('Recompiling - an import file has been moved.');

                return false;
            }
        }

        $files_changed = $data['datem_sum'] != array_sum($file_sums);
        if ($files_changed) {
            debug('Files have been modified. Recompiling.');
        }

        // Compare runtime options and cached options for differences.
        // Cast because the cached options may be a \stdClass if an IO adapter has been used.
        $options_changed = false;
        $cached_options = (array) $data['options'];
        $active_options = $options->get();
        foreach ($cached_options as $key => &$value) {
            if (isset($active_options[$key]) && $active_options[$key] !== $value) {
                debug('Options have been changed. Recompiling.');
                $options_changed = true;
                break;
            }
        }

        if (! $options_changed && ! $files_changed) {
            debug("Files and options have not been modified, returning cached file.");

            return true;
        }
        else {
            $data['datem_sum'] = array_sum($file_sums);

            return false;
        }
    }

    public static function getCacheData()
    {
        $config = Crush::$config;
        $logger = $config->logger;
        $process = Crush::$process;

        if (
            file_exists($process->cacheFile) &&
            $process->cacheData
        ) {
            // Already loaded and config file exists in the current directory
            return;
        }

        $cache_data_exists = file_exists($process->cacheFile);
        $cache_data_file_is_writable = $cache_data_exists ? is_writable($process->cacheFile) : false;
        $cache_data = array();

        if (
            $cache_data_exists &&
            $cache_data_file_is_writable &&
            $cache_data = json_decode(file_get_contents($process->cacheFile), true)
        ) {
            // Successfully loaded config file.
            debug('Cache data loaded.');
        }
        else {
            // Config file may exist but not be writable (may not be visible in some ftp situations?)
            if ($cache_data_exists) {
                if (! @unlink($process->cacheFile)) {
                    notice('[[CssCrush]] - Could not delete cache data file.');
                }
            }
            else {
                debug('Creating cache data file.');
            }
            Util::filePutContents($process->cacheFile, json_encode(array()), __METHOD__);
        }

        return $cache_data;
    }

    public static function saveCacheData()
    {
        $process = Crush::$process;
        $logger = Crush::$config->logger;

        debug('Saving config.');

        $flags = defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT : 0;
        Util::filePutContents($process->cacheFile, json_encode($process->cacheData, $flags), __METHOD__);
    }

    public static function write(Stream $stream)
    {
        $process = Crush::$process;
        $output = $process->output;
        $source_map_filename = "$output->filename.map";

        if ($process->sourceMap) {
            $stream->append($process->newline . "/*# sourceMappingURL=$source_map_filename */");
        }

        if (Util::filePutContents("$output->dir/$output->filename", $stream, __METHOD__)) {

            $json_encode_flags = defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT : 0;

            if ($process->sourceMap) {
                Util::filePutContents("$output->dir/$source_map_filename",
                    json_encode($process->sourceMap, $json_encode_flags), __METHOD__);
            }

            if ($process->options->stat_dump) {
                $stat_file = is_string($process->options->stat_dump) ?
                    $process->options->stat_dump : "$output->dir/$output->filename.json";

                $GLOBALS['CSSCRUSH_STAT_FILE'] = $stat_file;
                Util::filePutContents($stat_file, json_encode(csscrush_stat(), $json_encode_flags), __METHOD__);
            }

            return true;
        }

        return false;
    }
}
