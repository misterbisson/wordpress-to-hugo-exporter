<?php

/*
Plugin Name: WordPress to Hugo Exporter
Description: Exports WordPress posts, pages, and options as YAML files parsable by Hugo
Version: 1.3
Author: Benjamin J. Balter
Author URI: http://ben.balter.com
License: GPLv3 or Later

Copyright 2012-2013  Benjamin J. Balter  (email : Ben@Balter.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class Hugo_Export
{
    protected $_tempDir = null;
    private $zip_folder = 'hugo-export/'; //folder zip file extracts to
    private $post_folder = 'posts/'; //folder to place posts within

    /**
     * Manually edit this private property and set it to TRUE if you want to export
     * the comments as part of you posts. Pingbacks won't get exported.
     *
     * @var bool
     */
    // set TRUE by Casey
    private $include_comments = TRUE; //export comments as part of the posts they're associated with

    public $rename_options = array('site', 'blog'); //strings to strip from option keys on export

    public $options = array( //array of wp_options value to convert to config.yaml
        'name',
        'description',
        'url'
    );

    public $required_classes = array(
        'spyc' => '%pwd%/includes/spyc.php',
        'Markdownify\Parser' => '%pwd%/includes/markdownify/Parser.php',
        'Markdownify\Converter' => '%pwd%/includes/markdownify/Converter.php',
        'Markdownify\ConverterExtra' => '%pwd%/includes/markdownify/ConverterExtra.php',
    );

    /**
     * Hook into WP Core
     */
    function __construct()
    {

        add_action('admin_menu', array(&$this, 'register_menu'));
        add_action('current_screen', array(&$this, 'callback'));
    }

    /**
     * Listens for page callback, intercepts and runs export
     */
    function callback()
    {

        if (get_current_screen()->id != 'export')
            return;

        if (!isset($_GET['type']) || $_GET['type'] != 'hugo')
            return;

        if (!current_user_can('manage_options'))
            return;

        $this->export();
        exit();
    }

    /**
     * Add menu option to tools list
     */
    function register_menu()
    {
        add_management_page(__('Export to Hugo', 'hugo-export'), __('Export to Hugo', 'hugo-export'), 'manage_options', 'export.php?type=hugo');
    }

    /**
     * Get an array of all post and page IDs
     * Note: We don't use core's get_posts as it doesn't scale as well on large sites
     */
    function get_posts()
    {

// @TODO: need to handle post_type=scrib-authority
        global $wpdb;
        return $wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE post_status in ('publish', 'draft', 'private') AND post_type IN ('post', 'page' )");
    }

    /**
     * @param WP_Post $post
     *
     * @return bool|string
     */
    protected function _getPostDateAsIso(WP_Post $post)
    {
        // Dates in the m/d/y or d-m-y formats are disambiguated by looking at the separator between the various components: if the separator is a slash (/),
        // then the American m/d/y is assumed; whereas if the separator is a dash (-) or a dot (.), then the European d-m-y format is assumed.
        $unixTime = strtotime($post->post_date_gmt);
        return date('c', $unixTime);
    }

    /**
     * @param WP_Post $post
     *
     * @return bool|string
     */
    protected function _getPostDateAsMemento(WP_Post $post)
    {
        $unixTime = strtotime($post->post_date_gmt);
        return date('YmdHis', $unixTime);
    }

    /**
     * Convert a posts meta data (both post_meta and the fields in wp_posts) to key value pairs for export
     */
    function convert_meta(WP_Post $post)
    {
        $output = array(
            'title' => html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_XML1, 'UTF-8'),
            'slug' => $post->post_name,
            'author' => get_userdata($post->post_author)->display_name,
            'type' => get_post_type($post),
            'date' => $this->_getPostDateAsIso($post),
        );
        if (false === empty($post->post_excerpt)) {
            $output['excerpt'] = $post->post_excerpt;
        }

        if (in_array($post->post_status, array('draft', 'private'))) {
            // Mark private posts as drafts as well, so they don't get
            // inadvertently published.
            $output['draft'] = true;
        }
        if ($post->post_status == 'private') {
            // hugo doesn't have the concept 'private posts' - this is just to
            // disambiguate between private posts and drafts.
            $output['private'] = true;
        }

        // add URL aliases for the content
        // https://gohugo.io/content-management/urls/
        $output['aliases'] = array(
            urldecode( str_replace( home_url(), '', get_permalink( $post ))),
            '/?p=' . $post->ID,
        );
        if ('page' == $post->post_type)
        {
            $output['aliases'] = '/?page_id=' . $post->ID;
        }
        $output['aliases'] = array_unique( $output['aliases'] );

        $output['wp_guid'] = urldecode( $post->guid );


        //turns permalink into 'url' format, since Hugo supports redirection on per-post basis
//        $output['url'] = urldecode(str_replace(home_url(), '', get_permalink($post)));

        // check if the post or page has a Featured Image assigned to it.
        if (has_post_thumbnail($post)) {
            //$output['featured_image'] = str_replace(get_site_url(), "", get_the_post_thumbnail_url($post));
            // added by Casey to get the original item
            $output['featured_image'] = str_replace(get_site_url(), "", wp_get_attachment_url( get_post_thumbnail_id( $post->ID )));
        }

        //convert traditional post_meta values, hide hidden values
        foreach (get_post_custom($post->ID) as $key => $value) {
            if (substr($key, 0, 1) == '_') {
                continue;
            }
            // exclusions added by Casey
            if ( in_array( $key, array(
                'mct_proposed_tags',
                'go_oc_settings',
                'bgeo',
                'go-opencalais',
                'yourls_shorturl',
            ))) {
                continue;
            }
            if (false === $this->_isEmpty($value)) {
                $output[$key] = $value;
            }
        }
        return $output;
    }

    protected function _isEmpty($value)
    {
        if (true === is_array($value)) {
            if (true === empty($value)) {
                return true;
            }
            if (1 === count($value) && true === empty($value[0])) {
                return true;
            }
            return false;
//            $isEmpty=true;
//            foreach($value as $k=>$v){
//                if(true === empty($v)){
//                    $isEmpty
//                }
//            }
//            return $isEmpty;
        }
        return true === empty($value);
    }

    /**
     * Convert post taxonomies for export
     */
    function convert_terms($post)
    {

        $output = array();
        foreach (get_taxonomies(array('object_type' => array(get_post_type($post)))) as $tax) {

            $terms = wp_get_post_terms($post, $tax);

            //convert tax name for Hugo
            switch ($tax) {
                case 'post_tag':
                    $tax = 'tags';
                    break;
                case 'category':
                    $tax = 'categories';
                    break;
            }

            if ($tax == 'post_format') {
                $output['format'] = get_post_format($post);
            } else {
                $output[$tax] = wp_list_pluck($terms, 'name');
            }
        }

        return $output;
    }

    /**
     * Find all URLs in the post
     */
    function find_urls( $post )
    {
        $content = apply_filters('the_content', $post->post_content);

        preg_match_all( '#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $content, $urls );

        if ( ! isset( $urls[0] ) )
        {
            return array();
        }

        sort( $urls[0] );
        $urls = array_unique( $urls[0] );

        return $urls;
    }

    /**
     * the goal here is, primarily, to copy media
     * used used in this post to the Hugo post bundle
     *
     * Local media:
     *   - try to copy it on the file system to the content bundle
     *   - else, try to find it in Internet Archive and download it
     *
     * Remote media:
     *   - try to import it from the remote location into the content bundle
     *   - else, try to find it in Internet Archive and download it
     *
     */
    function convert_linked_media( $post , $dirpath )
    {

        global $wp_filesystem;
        $source_dir = wp_upload_dir();
        $source_dir = dirname( $source_dir['basedir'] );

        $media_extensions = array(
            'jpg',
            'jpeg',
            'gif',
            'png',
            'pdf',

            'mp3',
            'm4a',
            'oga',

            'mov',
        );

        $imported_media = $recovered_media = $lost_media = array();
        foreach( $this->find_urls( $post ) as $k => $url )
        {

            // WordPress encodes ampersands; they need decoding
            $url_stripped_amps = str_replace( '&amp;', '&', $url );

            // ignore links that don't appear to be media
            if ( ! in_array(
                pathinfo( $url_stripped_amps, PATHINFO_EXTENSION ),
                $media_extensions
            ))
            {
                continue;
            }

            // try to process local links on the filesystem
            if ( FALSE !== strpos( $url_stripped_amps, get_site_url( NULL, '', parse_url( $url_stripped_amps,  PHP_URL_SCHEME ))))
            {
                // get the relative URL
                $url_stripped_amps = str_replace( get_site_url( NULL, '', parse_url ( $url_stripped_amps,  PHP_URL_SCHEME )), '', $url_stripped_amps );

                // copy the file
                $filename = pathinfo( $url_stripped_amps, PATHINFO_BASENAME );
                $wp_filesystem->copy( $source_dir . $url_stripped_amps, $dirpath . $filename );

                // record meta, then move on to the next URL (no further processing here)
                $imported_media[ $filename ] = $url;
                continue;
            }

            // If we are still here, that means it's a remote file
            // construct a filename that includes the remote host name
            $filename = sanitize_title_with_dashes( parse_url( $url_stripped_amps,  PHP_URL_HOST )) . '-' . pathinfo( $url_stripped_amps, PATHINFO_BASENAME );

            // try to fetch remote object from the web
            $get = wp_remote_post( $url_stripped_amps );
            if ( is_array( $get ) && 200 == $get['response']['code'] )
            {
                // write the returned body to the file
                $wp_filesystem->put_contents( $dirpath . $filename, $get['body'] );

                // record meta, then move on to the next URL (no further processing here)
                $imported_media[ $filename ] = $url;
                continue;
            }

            // If we are still here, that means we got an error when attempting to download the remote file
            // try to find it in the Internet Archive
            // api docs at https://archive.org/help/wayback_api.php
            $get = wp_remote_get( 'http://archive.org/wayback/available?timestamp=' . $this->_getPostDateAsMemento( $post ) . '&url=' . $url );
            if ( is_array( $get ) && 200 == $get['response']['code'] )
            {
                $archive_deets = json_decode( $get['body'] );

                if ( isset( $archive_deets->archived_snapshots->closest->url ))
                {
                    $archive_url =  str_replace( '/http', 'if_/http', $archive_deets->archived_snapshots->closest->url );
fwrite(STDOUT, print_r( $archive_url, TRUE ) . "\n\n" );

                    $get = wp_remote_post( $archive_url );
                    if ( is_array( $get ) && 200 == $get['response']['code'] )
                    {
                        // write the returned body to the file
                        $wp_filesystem->put_contents( $dirpath . $filename, $get['body'] );

                        // record meta, then move on to the next URL (no further processing here)
                        $recovered_media[ $filename ] = $url;
                        continue;
                    }
                }
            }

            // If we are still here, that means we got an error when attempting to download the remote file FROM THE INTERNET ARCHIVE
            // report the loss
            // People should also try http://timetravel.mementoweb.org for more potential sources
            $lost_media[ $filename ] = $url;
        }

        return array(
            'imported-media' => $imported_media,
            'recovered-media' => $recovered_media,
            'lost-media' => $lost_media,
        );
    }

    /**
     * XXX
     */
    function convert_attachments( $postID , $dirpath )
    {

        global $wp_filesystem;

        $children = get_children( array(
        	'post_parent' => $postID,
        	'post_type'   => 'attachment',
        	'numberposts' => -1,
        	'post_status' => 'any'
        ));

        $imported_attachments = array();
        foreach ( $children as $child )
        {
            $source_url = wp_get_attachment_url( $child->ID );
            $source = get_attached_file( $child->ID );
            $filename = pathinfo( $source, PATHINFO_BASENAME );

            $wp_filesystem->copy( $source, $dirpath . $filename );

            $imported_attachments[ $filename ] = $source_url;
        }

        return array( 'imported-attachments' => $imported_attachments );
    }

    /**
     * Convert the main post content to Markdown.
     */
    function convert_content($post)
    {

        $content = apply_filters('the_content', $post->post_content);

        // additional filters
        // remove bSuite/bCMS innerindex blocks
        $content = preg_replace( '/<div class="contents innerindex">.*?<\/div>/is', '', $content );

        $converter = new Markdownify\ConverterExtra;
        $markdown = $converter->parseString($content);

        if (false !== strpos($markdown, '[]: ')) {
            // faulty links; return plain HTML
            return $content;
        }

        return $markdown;
    }

    /**
     * Loop through and convert all comments for the specified post
     */
    function convert_comments($post, $dirpath)
    {
        $args = array(
            'post_id' => $post->ID,
            'order' => 'ASC',   // oldest comments first
            'type' => 'comment'
        );
        $comments = get_comments($args);
        if (empty($comments)) {
            return '';
        }

        $meta = array(
            'title' => 'Comments on ' . html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_XML1, 'UTF-8'),
            'date' => $this->_getPostDateAsIso($post),
        );

        $converter = new Markdownify\ConverterExtra;
        $comment_text = "\n\n## Comments";
        foreach ($comments as $comment)
        {
            $meta['comments'][ $comment->comment_ID ] = array(
                'type' => $comment->comment_type,
                'author' => $comment->comment_author,
                'email' => $comment->comment_author_email,
                'url' => esc_url( $comment->comment_author_url ),
                'date_gmt' => date('c', strtotime( $comment->comment_date_gmt )),
            );
            $content = $meta['comments'][ $comment->comment_ID ]['text'] = $converter->parseString( get_comment_text( $comment->comment_ID ));
            $comment_text .= "\n\n\n### Comment by " . $comment->comment_author . " on " . get_comment_date("Y-m-d H:i:s O", $comment) . "\n\n";
            $comment_text .= $content;
        }

        // Hugo doesn't like word-wrapped permalinks
        $output = Spyc::YAMLDump($meta, false, 0);
        $output .= "\n---\n" . $comment_text;

        // write out the comments as a separate file
        $this->write($output, $dirpath . 'comments.md');

        // return empty because we're writing the comments out as a separate file
        return '';
    }

    /**
     * Loop through and convert all posts to MD files with YAML headers
     */
    function convert_posts()
    {
        // remove WP's responsive image injection
        // it makes the markup noisier and we want just the basics
        // https://rudrastyh.com/wordpress/responsive-images.html
        remove_filter( 'the_content', 'wp_make_content_images_responsive' );

        global $post;

        foreach ($this->get_posts() as $postID) {
            $post = get_post($postID);
            setup_postdata($post);

            $dirpath = $this->make_dest_dir($post);

            // get all post meta, terms, and other bits as a single array
            $meta = array_merge(
                $this->convert_meta($post),
                $this->convert_terms($postID),
                $this->convert_attachments($postID, $dirpath),
                $this->convert_linked_media($post, $dirpath)
            );

            // remove falsy values, which just add clutter
            foreach ($meta as $key => $value) {
                if (!is_numeric($value) && !$value) {
                    unset($meta[$key]);
                }
            }

            // Hugo doesn't like word-wrapped permalinks
            $output = Spyc::YAMLDump($meta, false, 0);

            $output .= "\n---\n";
            $output .= $this->convert_content($post);
            if ($this->include_comments) {
                $output .= $this->convert_comments($post, $dirpath);
            }
            $this->write($output, $dirpath . 'index.md');

            // write progress to the CLI
            fwrite(STDOUT, '.' );
        }
    }

    function filesystem_method_filter()
    {
        return 'direct';
    }

    /**
     *  Conditionally Include required classes
     */
    function require_classes()
    {

        foreach ($this->required_classes as $class => $path) {
            if (class_exists($class)) {
                continue;
            }
            $path = str_replace("%pwd%", dirname(__FILE__), $path);
            require_once($path);
        }
    }

    /**
     * Main function, bootstraps, converts, and cleans up
     */
    function export()
    {
        global $wp_filesystem;

        define('DOING_JEKYLL_EXPORT', true);

        $this->require_classes();

        add_filter('filesystem_method', array(&$this, 'filesystem_method_filter'));

        WP_Filesystem();

        $urlbits = parse_url( site_url() );

        $this->dir = $this->getTempDir() . 'wp-hugo-' . $urlbits['host'] . sanitize_title_with_dashes( $urlbits['path'] ) . '-' . md5(time()) . '/';
        $this->zip = $this->getTempDir() . 'wp-hugo.zip';
        $wp_filesystem->mkdir($this->dir);
        $wp_filesystem->mkdir($this->dir . $this->post_folder);
        $wp_filesystem->mkdir($this->dir . 'wp-content/');

        $this->convert_options();
        $this->convert_posts();
// Disabled by Casey
//        $this->convert_uploads();
//        $this->zip();
//        $this->send();
//        $this->cleanup();
    }

    /**
     * Convert options table to config.yaml file
     */
    function convert_options()
    {

        global $wp_filesystem;

        $options = wp_load_alloptions();
        foreach ($options as $key => &$option) {

            if (substr($key, 0, 1) == '_')
                unset($options[$key]);

            //strip site and blog from key names, since it will become site. when in Hugo
            foreach ($this->rename_options as $rename) {

                $len = strlen($rename);
                if (substr($key, 0, $len) != $rename)
                    continue;

                $this->rename_key($options, $key, substr($key, $len));
            }

            $option = maybe_unserialize($option);
        }

        foreach ($options as $key => $value) {

            if (!in_array($key, $this->options))
                unset($options[$key]);
        }

        $output = Spyc::YAMLDump($options);

        //strip starting "---"
        $output = substr($output, 4);

        $wp_filesystem->put_contents($this->dir . 'config.yaml', $output);
    }

    /**
     * Write file to temp dir
     */
    function make_dest_dir($post)
    {

        global $wp_filesystem;

        if (get_post_type($post) == 'page') {
            $dirpath = urldecode($this->dir . $post->post_name . '/');
        } else {
            $dirpath = $this->dir . $this->post_folder . date('Y-m-d', strtotime($post->post_date)) . '-' . urldecode($post->post_name . '/');
        }

        $wp_filesystem->mkdir( $dirpath );
        return $dirpath;
    }

    /**
     * Write file to temp dir
     */
    function write($output, $writepath)
    {

//        fwrite(STDOUT, $writepath . "\n" );

        global $wp_filesystem;
        $wp_filesystem->put_contents($writepath, $output);
    }

    /**
     * Zip temp dir
     */
    function zip()
    {

        //create zip
        $zip = new ZipArchive();
        $err = $zip->open($this->zip, ZIPARCHIVE::CREATE | ZIPARCHIVE::OVERWRITE);
        if ($err !== true) {
            die("Failed to create '$this->zip' err: $err");
        }
        $this->_zip($this->dir, $zip);
        $zip->close();
    }

    /**
     * Helper function to add a file to the zip
     */
    function _zip($dir, &$zip)
    {

        //loop through all files in directory
        foreach ((array)glob(trailingslashit($dir) . '*') as $path) {

            // periodically flush the zipfile to avoid OOM errors
            if ((($zip->numFiles + 1) % 250) == 0) {
                $filename = $zip->filename;
                $zip->close();
                $zip->open($filename);
            }

            if (is_dir($path)) {
                $this->_zip($path, $zip);
                continue;
            }

            //make path within zip relative to zip base, not server root
            $local_path = str_replace($this->dir, $this->zip_folder, $path);

            //add file
            $zip->addFile(realpath($path), $local_path);
        }
    }

    /**
     * Send headers and zip file to user
     */
    function send()
    {
        if ('cli' === php_sapi_name()) {
            echo "\nThis is your file!\n$this->zip\n";
            return null;
        }

        //send headers
        @header('Content-Type: application/zip');
        @header("Content-Disposition: attachment; filename=hugo-export.zip");
        @header('Content-Length: ' . filesize($this->zip));

        //read file
        ob_clean();
        flush();
        readfile($this->zip);
    }

    /**
     * Clear temp files
     */
    function cleanup()
    {
        global $wp_filesystem;
        $wp_filesystem->delete($this->dir, true);
        if ('cli' !== php_sapi_name()) {
            $wp_filesystem->delete($this->zip);
        }
    }

    /**
     * Rename an assoc. array's key without changing the order
     */
    function rename_key(&$array, $from, $to)
    {

        $keys = array_keys($array);
        $index = array_search($from, $keys);

        if ($index === false)
            return;

        $keys[$index] = $to;
        $array = array_combine($keys, $array);
    }

    function convert_uploads()
    {

        $upload_dir = wp_upload_dir();
        $this->copy_recursive($upload_dir['basedir'], $this->dir . str_replace(trailingslashit(get_home_url()), '', $upload_dir['baseurl']));
    }

    /**
     * Copy a file, or recursively copy a folder and its contents
     *
     * @author      Aidan Lister <aidan@php.net>
     * @version     1.0.1
     * @link        http://aidanlister.com/2004/04/recursively-copying-directories-in-php/
     *
     * @param       string $source Source path
     * @param       string $dest Destination path
     *
     * @return      bool     Returns TRUE on success, FALSE on failure
     */
    function copy_recursive($source, $dest)
    {

        global $wp_filesystem;

        // Check for symlinks
        if (is_link($source)) {
            return symlink(readlink($source), $dest);
        }

        // Simple copy for a file
        if (is_file($source)) {
            return $wp_filesystem->copy($source, $dest);
        }

        // Make destination directory
        if (!is_dir($dest)) {
            if (!wp_mkdir_p($dest)) {
                $wp_filesystem->mkdir($dest) or wp_die("Could not created $dest");
            }
        }

        // Loop through the folder
        $dir = dir($source);
        while (false !== $entry = $dir->read()) {
            // Skip pointers
            if ($entry == '.' || $entry == '..') {
                continue;
            }

            // Deep copy directories
            $this->copy_recursive("$source/$entry", "$dest/$entry");
        }

        // Clean up
        $dir->close();
        return true;
    }

    /**
     * @param null $tempDir
     */
    public function setTempDir($tempDir)
    {
        $this->_tempDir = $tempDir . (false === strpos($tempDir, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '');
    }

    /**
     * @return null
     */
    public function getTempDir()
    {
        if (null === $this->_tempDir) {
            $this->_tempDir = get_temp_dir();
        }
        return $this->_tempDir;
    }
}

global $je;
$je = new Hugo_Export();

if (defined('WP_CLI') && WP_CLI) {

    class Hugo_Export_Command extends WP_CLI_Command
    {

        function __invoke()
        {
            global $je;

            $je->export();
        }
    }

    WP_CLI::add_command('hugo-export', 'Hugo_Export_Command');
}
