<?php

/**
 * Plugin Name: Comfort of Home — Newsletter Downloads (PDFs)
 * Description: Simple admin page to manage PDFs in /downloads (public_html/downloads).
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 *
 */
class COH_Downloads {
  const NONCE = 'coh_downloads_nonce';
  private $dir;
  private $url;

  public function __construct() {
    $this->dir = trailingslashit(ABSPATH) . 'downloads';
    $this->url = trailingslashit(site_url('/downloads'));

    add_action('admin_menu', [$this, 'menu']);
    add_action('admin_post_coh_upload_pdf', [$this, 'handle_upload']);
    add_action('admin_post_coh_delete_pdf', [$this, 'handle_delete']);
  }

  /**
   *
   */
  public function menu() {
    add_menu_page(
          'Newsletter Downloads (PDFs)',
          'Newsletter Downloads (PDFs)',
          'manage_options',
          'coh-downloads',
          [$this, 'render'],
          'dashicons-media-document',
          81
      );
  }

  /**
   *
   */
  private function ensure_dir() {
    if (!file_exists($this->dir)) {
      wp_mkdir_p($this->dir);
    }
    if (!is_dir($this->dir) || !is_writable($this->dir)) {
      wp_die('The /downloads folder is missing or not writable. Ask your host to create <code>' . esc_html($this->dir) . '</code> with write permissions.');
    }
  }

  /**
   *
   */
  public function render() {
    if (!current_user_can('manage_options')) {
      wp_die('Unauthorized');
    }
    $this->ensure_dir();

    $files = glob($this->dir . '/*.pdf');
    $files = $files ? array_map('basename', $files) : [];

    echo '<div class="wrap"><h1>Newsletter Downloads (PDFs)</h1>';

    // Upload form.
    echo '<h2>Upload a PDF</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
    wp_nonce_field(self::NONCE, '_wpnonce');
    echo '<input type="hidden" name="action" value="coh_upload_pdf">';
    echo '<input type="file" name="coh_pdf" accept="application/pdf" required> ';
    submit_button('Upload PDF', 'primary', 'submit', FALSE);
    echo '</form>';

    // Existing files.
    echo '<h2 style="margin-top:2em;">Existing PDFs</h2>';
    if (empty($files)) {
      echo '<p>No PDFs found in <code>/downloads</code>.</p>';
    }
    else {
      echo '<table class="widefat striped" style="max-width:900px">';
      echo '<thead><tr><th>File</th><th>Size</th><th>URL</th><th>Actions</th></tr></thead><tbody>';
      foreach ($files as $file) {
        $path = $this->dir . '/' . $file;
        $url  = $this->url . $file;
        $size = size_format(filesize($path));
        echo '<tr>';
        echo '<td>' . esc_html($file) . '</td>';
        echo '<td>' . esc_html($size) . '</td>';
        echo '<td><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a></td>';
        echo '<td>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'ARE YOU SURE? Delete this file? Deletes are permanent.\')">';
        wp_nonce_field(self::NONCE, '_wpnonce');
        echo '<input type="hidden" name="action" value="coh_delete_pdf">';
        echo '<input type="hidden" name="file" value="' . esc_attr($file) . '">';
        submit_button('Delete (PERMANENT)', 'delete', 'submit', FALSE);
        echo '</form>';
        echo '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
    }

    echo '<p style="margin-top:1em;color:#555">Folder path: <code>' . esc_html($this->dir) . '</code></p>';
    echo '</div>';
  }

  /**
   *
   */
  public function handle_upload() {
    if (!current_user_can('manage_options')) {
      wp_die('Unauthorized');
    }
    check_admin_referer(self::NONCE);
    $this->ensure_dir();

    if (empty($_FILES['coh_pdf']['tmp_name'])) {
      $this->redirect('No file received.');
    }

    // Sanitize filename and force .pdf.
    $name = sanitize_file_name($_FILES['coh_pdf']['name']);
    if (!preg_match('/\.pdf$/i', $name)) {
      $name .= '.pdf';
    }

    $tmp = $_FILES['coh_pdf']['tmp_name'];

    // Validate mimetype with finfo (server-side)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $tmp) : '';
    if ($finfo) {
      finfo_close($finfo);
    }
    if (strtolower($mime) !== 'application/pdf') {
      $this->redirect('Only PDF files are allowed.');
    }

    // Final path.
    $dest = $this->dir . '/' . $name;

    // Prevent overwrite by auto-suffixing.
    $base = pathinfo($name, PATHINFO_FILENAME);
    $i = 1;
    while (file_exists($dest)) {
      $name2 = $base . '-' . $i . '.pdf';
      $dest  = $this->dir . '/' . $name2;
      $i++;
    }

    if (!@move_uploaded_file($tmp, $dest)) {
      $this->redirect('Upload failed (permissions?).');
    }

    // Tighten permissions a bit.
    @chmod($dest, 0644);

    $this->redirect('Upload successful.');
  }

  /**
   *
   */
  public function handle_delete() {
    if (!current_user_can('manage_options')) {
      wp_die('Unauthorized');
    }
    check_admin_referer(self::NONCE);
    $this->ensure_dir();

    $file = isset($_POST['file']) ? wp_basename(wp_unslash($_POST['file'])) : '';
    if (!$file || !preg_match('/\.pdf$/i', $file)) {
      $this->redirect('Invalid file. Only `.pdf` files are allowed.');
    }

    $path = $this->dir . '/' . $file;

    // Ensure path is inside our dir.
    $realDir  = realpath($this->dir);
    $realFile = realpath($path);
    if (!$realFile || strpos($realFile, $realDir) !== 0) {
      $this->redirect('Invalid path.');
    }

    if (!file_exists($path) || !is_file($path)) {
      $this->redirect('File not found.');
    }

    if (!@unlink($path)) {
      $this->redirect('Delete failed (permissions?).');
    }

    $this->redirect('File deleted.');
  }

  /**
   *
   */
  private function redirect($msg) {
    $url = add_query_arg(['page' => 'coh-downloads', 'coh_msg' => rawurlencode($msg)], admin_url('admin.php'));
    wp_safe_redirect($url);
    exit;
  }

}

new COH_Downloads();
