EAM — shared-hosting package
============================

Layout:
  public_html/            web root
    index.php             front controller
    .htaccess             denies _app and routes requests
    assets/               public CSS/JS
    _app/                 private application directory
      src/ templates/ lang/ storage/ config/

Configuration (external config wins):
  1. EAM_CONFIG environment variable (absolute path)
  2. config.php one level above public_html
  3. public_html/_app/config/config.php (inside _app, denied by .htaccess)

Quick start:
  1. Copy this directory to the hosting account.
  2. Create the external config:
       cp config.example.php config.php
     or keep it inside the app:
       cp public_html/_app/config/config.example.php public_html/_app/config/config.php
  3. Edit config.php. For storage paths use EAM_APP_DIR:
       EAM_APP_DIR . '/storage/eam.sqlite'
       EAM_APP_DIR . '/storage/data'
       EAM_APP_DIR . '/storage/sessions'
  4. Make storage writable:
       chmod -R u+rwX public_html/_app/storage
  5. Ensure Apache allows .htaccess (AllowOverride FileInfo; Options for -Indexes).
  6. Verify that private files are blocked:
       curl -I https://example.com/_app/config/config.php   # 403/404
       curl -I https://example.com/_app/storage/eam.sqlite  # 403/404
       curl -I https://example.com/_app/src/App.php         # 403/404
       curl -I https://example.com/assets/css/style.css     # 200
       curl -I https://example.com/registration             # 200

If .htaccess is ignored, this layout is not safe. Use the standard layout
with the document root pointing to public/ instead.

See docs/SHARED_HOSTING.md for details and Nginx examples.
