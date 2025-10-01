<?php
class ControllerCommonImportProduct extends Controller {
    public function index() {
        // GET: render form
        if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
            $data['heading_title'] = 'Import Produk Shopee';
            $data['action']        = $this->url->link('common/import_product', '', true);
            $data['categories']    = $this->getCategories(); // sorted di query: ORDER BY c.category_id ASC
            $this->response->setOutput($this->load->view('common/import_product', $data));
            return;
        }

        // POST: process import
        $this->response->addHeader('Content-Type: application/json');

        // Ambil category_id
        $category_id = (int)($this->request->post['category_id'] ?? 0);
        if ($category_id <= 0) {
            $this->response->setOutput(json_encode([
                'success' => false,
                'error'   => 'category_id kosong/invalid'
            ]));
            return;
        }

        // Ambil raw HTML: prioritas base64 agar lolos sanitasi OpenCart/WAF
        $raw_html = '';
        if (!empty($this->request->post['raw_html_b64'])) {
            // Decode base64
            $raw_html = base64_decode($this->request->post['raw_html_b64']);
        } else {
            // Ambil langsung dari $_POST untuk bypass Request->clean()
            if (isset($_POST['raw_html'])) {
                $raw_html = (string)$_POST['raw_html'];
            } else {
                $raw_html = (string)($this->request->post['raw_html'] ?? '');
            }
            // Decode entities jika sudah ter-escape (e.g. &lt;div&gt;)
            if ($raw_html !== '') {
                $raw_html = htmlspecialchars_decode($raw_html, ENT_QUOTES);
                $raw_html = html_entity_decode($raw_html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (function_exists('ini_get') && ini_get('magic_quotes_gpc')) {
                    $raw_html = stripslashes($raw_html);
                }
            }
        }

        if ($raw_html === '') {
            $this->response->setOutput(json_encode([
                'success' => false,
                'error'   => 'raw_html kosong'
            ]));
            return;
        }

        // Parse → Download → Import
        try {
            // Parse produk dari HTML
            $products = $this->getProductsFromHtml($raw_html, $category_id);

            // Jika parser kosong, balas sukses=false
            if (empty($products)) {
                $this->response->setOutput(json_encode([
                    'success'  => false,
                    'error'    => 'Parser tidak menemukan produk (cek pola HTML / kirim via base64).',
                    'products' => []
                ]));
                return;
            }

            // Download images
            $dl = $this->downloadImages($products); // akan set $products[*]['image']

            // Import ke DB
            $res = $this->importProducts($products);

            $this->response->setOutput(json_encode([
                'success'  => (bool)($res['success'] ?? false),
                'download' => $dl,
                'products' => ['count'=>count($products)],
                'import'   => $res
            ]));
        } catch (\Throwable $e) {
            $this->response->setOutput(json_encode([
                'success' => false,
                'error'   => $e->getMessage()
            ]));
        }
    }
    public function dump(){
        echo $this->session->data['category_id'];
        echo "<hr/>";
        echo $this->session->data['raw_html'];
        echo "<hr/>";
        echo $this->session->data['raw_html_2'];
        echo "<hr/>";
        echo $this->session->data['get_product_from'];

        echo "<hr/>";
        echo "<pre>";

        print_r($this->getProductsFromFile(10));
        echo "</pre>";
    }

    private function getCategories(): array {
        $language_id = (int)$this->config->get('config_language_id');
        $store_id    = (int)$this->config->get('config_store_id');

        $sql = "SELECT c.category_id, cd.name
            FROM `" . DB_PREFIX . "category` c
            JOIN `" . DB_PREFIX . "category_description` cd
                 ON (c.category_id = cd.category_id AND cd.language_id = '" . (int)$language_id . "')
            LEFT JOIN `" . DB_PREFIX . "category_to_store` c2s
                 ON (c.category_id = c2s.category_id)
            WHERE c.status = 1
              AND (c2s.store_id IS NULL OR c2s.store_id = '" . (int)$store_id . "')
            ORDER BY c.category_id ASC";

        $qry = $this->db->query($sql);
        return $qry->rows ?? [];
    }

    private function downloadImages(array &$products): array {
        $dir = $this->ensureImportDir();
        $ok = 0; $fail = [];

        foreach ($products as &$p) {
            if (empty($p['image_url'])) { continue; }

            $url = $p['image_url'];
            $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            if (!$ext) { $ext = 'jpg'; }

            $base = strtolower(preg_replace('/[^a-z0-9\-]+/','-', $p['model'] ?? uniqid('p')));
            $file = $base . '.' . $ext;
            $abs  = $dir . $file;

            $okDl = $this->curlDownload($url, $abs);
            if ($okDl) {
                $p['image'] = 'catalog/import/' . $file;
                $ok++;
            } else {
                $fail[] = ['model'=>$p['model'] ?? '', 'url'=>$url];
            }
        }
        return ['downloaded'=>$ok, 'failed'=>$fail];
    }

    private function ensureImportDir(): string {
        $dir = rtrim(DIR_IMAGE, '/').'/catalog/import/';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        return $dir;
    }

    private function curlDownload(string $url, string $destAbs): bool {
        $fp = @fopen($destAbs, 'w');
        if (!$fp) { return false; }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Importer/1.0)',
            CURLOPT_REFERER => 'https://shopee.co.id/',
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code >= 400) { @unlink($destAbs); return false; }
        return true;
    }

    private function importProducts(array $products): array {
        $inserted = 0; $errors = [];
        foreach ($products as $p) {
            try {
                $model  = $this->db->escape($p['model']);
                $sku    = $this->db->escape($p['sku']);
                $price  = (float)$p['price'];
                $qty    = (int)($p['quantity'] ?? 0);
                $status = (int)($p['status'] ?? 0);
                $stock_status_id = (int)($p['stock_status_id'] ?? 0);
                $shipping = (int)($p['shipping'] ?? 1);
                $category_id = (int)$p['category_id'];
                $store_id = (int)($p['store_id'] ?? 0);
                $image = $this->db->escape($p['image'] ?? '');

                $manufacturer_id = 0;
                if (!empty($p['manufacturer_name'])) {
                    $manufacturer_id = $this->getOrCreateManufacturerId($p['manufacturer_name'], $store_id);
                }

                // --- DELETE jika ada product dengan nama persis sama ---
                foreach ($p['names'] as $language_id => $name) {
                    $name = $this->db->escape($name);
                    $query = $this->db->query("SELECT product_id FROM `".DB_PREFIX."product_description` WHERE name='{$name}' AND language_id=".(int)$language_id);
                    foreach ($query->rows as $row) {
                        $pid = (int)$row['product_id'];
                        // hapus dari semua table terkait
                        $this->db->query("DELETE FROM `".DB_PREFIX."product` WHERE product_id='{$pid}'");
                        $this->db->query("DELETE FROM `".DB_PREFIX."product_description` WHERE product_id='{$pid}'");
                        $this->db->query("DELETE FROM `".DB_PREFIX."product_to_category` WHERE product_id='{$pid}'");
                        $this->db->query("DELETE FROM `".DB_PREFIX."product_to_store` WHERE product_id='{$pid}'");
                        $this->db->query("DELETE FROM `".DB_PREFIX."seo_url` WHERE query='product_id={$pid}'");
                        $this->db->query("DELETE FROM `".DB_PREFIX."product_image` WHERE product_id='{$pid}'");
                        $this->db->query("DELETE FROM `".DB_PREFIX."product_special` WHERE product_id='{$pid}'");
                        $this->db->query("DELETE FROM `".DB_PREFIX."product_discount` WHERE product_id='{$pid}'");
                        $this->db->query("DELETE FROM `".DB_PREFIX."product_reward` WHERE product_id='{$pid}'");
                        $this->db->query("DELETE FROM `".DB_PREFIX."product_option` WHERE product_id='{$pid}'");
                        $this->db->query("DELETE FROM `".DB_PREFIX."product_attribute` WHERE product_id='{$pid}'");
                        $this->db->query("DELETE FROM `".DB_PREFIX."product_filter` WHERE product_id='{$pid}'");
                        $this->db->query("DELETE FROM `".DB_PREFIX."product_related` WHERE product_id='{$pid}'");
                    }
                }

                // --- INSERT baru ---
                $this->db->query("
          INSERT INTO `".DB_PREFIX."product`
          SET `model`='{$model}',
              `sku`='{$sku}',
              `manufacturer_id`='".(int)$manufacturer_id."',
              `price`='{$price}',
              `quantity`='{$qty}',
              `stock_status_id`='{$stock_status_id}',
              `shipping`='{$shipping}',
              `status`='{$status}',
              `image`='{$image}',
              `date_available`=CURDATE(),
              `date_added`=NOW(),
              `date_modified`=NOW()
        ");

                $product_id = (int)$this->db->getLastId();

                foreach ($p['names'] as $language_id => $name) {
                    $name = $this->db->escape($name);
                    $desc = $this->db->escape($p['descriptions'][$language_id] ?? '');
                    $meta = $this->db->escape($p['meta_titles'][$language_id] ?? $name);

                    $this->db->query("
            INSERT INTO `".DB_PREFIX."product_description`
            SET `product_id`='{$product_id}',
                `language_id`='".(int)$language_id."',
                `name`='{$name}',
                `description`='{$desc}',
                `meta_title`='{$meta}'
          ");
                }

                $this->db->query("INSERT IGNORE INTO `".DB_PREFIX."product_to_store` SET `product_id`='{$product_id}', `store_id`='{$store_id}'");
                $this->db->query("INSERT IGNORE INTO `".DB_PREFIX."product_to_category` SET `product_id`='{$product_id}', `category_id`='{$category_id}'");

                $inserted++;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        return ['success'=>empty($errors), 'inserted'=>$inserted, 'errors'=>$errors];
    }


    private function getOrCreateManufacturerId(string $name, int $store_id = 0): int {
        $name_esc = $this->db->escape($name);
        $q = $this->db->query("SELECT manufacturer_id FROM `".DB_PREFIX."manufacturer` WHERE name='{$name_esc}' LIMIT 1");
        if (!empty($q->row['manufacturer_id'])) {
            $mid = (int)$q->row['manufacturer_id'];
            $this->db->query("INSERT IGNORE INTO `".DB_PREFIX."manufacturer_to_store` SET `manufacturer_id`='{$mid}', `store_id`='{$store_id}'");
            return $mid;
        }
        $this->db->query("INSERT INTO `".DB_PREFIX."manufacturer` SET `name`='{$name_esc}'");
        $mid = (int)$this->db->getLastId();
        $this->db->query("INSERT INTO `".DB_PREFIX."manufacturer_to_store` SET `manufacturer_id`='{$mid}', `store_id`='{$store_id}'");
        return $mid;
    }

    public function getProducts($category_id, $htmlString = '') {
        if (!empty($htmlString)) {
            $this->session->data['get_product_from'] = 'string';
            $this->session->data['raw_html_2'] = $htmlString;
            return $this->getProductsFromHtml($htmlString, (int)$category_id);
        } else {
            $this->session->data['get_product_from'] = 'file';
            return $this->getProductsFromFile((int)$category_id);
        }
    }

    public function getProductsFromHtml($htmlString, $category_id) {
        $doc = new \DOMDocument();
        @$doc->loadHTML($htmlString);
        $xpath = new \DOMXPath($doc);
        // Match both shop-search-result-view__item and shop-collection-view__item
        $nodes = $xpath->query('//div[contains(@class,"shop-search-result-view__item") or contains(@class,"shop-collection-view__item")]');
        $out = [];

        foreach ($nodes as $el) {
            $nameNode = $xpath->query('.//div[contains(@class,"line-clamp-2")]', $el)->item(0);
            $name = $nameNode ? trim($nameNode->textContent) : 'N/A';

            $linkNode = $xpath->query('.//a[contains(@class,"contents")]', $el)->item(0);
            $pid = $linkNode ? preg_replace('/.*i\.\d+\.(\d+).*/', '$1', $linkNode->getAttribute('href')) : '';
            $model = $pid; // Use only the last part of the PID
            $sku = $pid;   // SKU is the same as model

            $priceNode = $xpath->query('.//span[contains(@class,"text-base/5") and contains(@class,"font-medium")]', $el)->item(0);
            $price = $priceNode ? (int)str_replace('.', '', trim($priceNode->textContent)) : 0;

            $soldNode = $xpath->query('.//div[contains(@class,"text-shopee-black87") and contains(@class,"text-xs")]', $el)->item(0);
            $qty = 0;
            if ($soldNode && preg_match('/(\d+)\s*terjual/', trim($soldNode->textContent), $matches)) {
                $qty = (int)$matches[1];
            }

            $imgNode = $xpath->query('.//div[@style="padding-top: 100%;"]//img[contains(@class,"inset-y-0")]', $el)->item(0);
            $img = $imgNode ? $imgNode->getAttribute('src') : '';
            if ($img) $img = str_replace('_tn.webp', '.webp', $img);

            // Check for out-of-stock status
            $outOfStockNode = $xpath->query('.//div[contains(@class,"rounded-full") and contains(text(), "Habis")]', $el)->item(0);
//            $status = $outOfStockNode ? 0 : 1; // Set status to 0 if out of stock, 1 if in stock
            $status = 1;
            $stockStatusId = $outOfStockNode ? 5 : 7; // Example: 5 for out of stock, 7 for in stock

            $mt = function($s){ return function_exists('mb_substr') ? mb_substr($s,0,60) : substr($s,0,60); };

            $out[] = [
                'model' => $model,
                'sku' => $sku,
                'manufacturer_name' => 'Unknown',
                'price' => $price,
                'quantity' => $qty,
                'status' => $status,
                'stock_status_id' => $stockStatusId,
                'shipping' => 1,
                'store_id' => 0,
                'category_id' => (int)$category_id,
                'image_url' => $img,
                'image' => '',
                'names' => [1 => $name, 4 => $name],
                'descriptions' => [1 => ($name), 4 => ($name)],
                'meta_titles' => [1 => $mt($name), 4 => $mt($name)]
            ];
        }
        return $out;
    }

    // Opsional: fallback lama
    private function getProductsFromFile($category_id) {
        $filePath = "https://hejorganic.id/data.html";
        $htmlString = @file_get_contents($filePath);
        if ($htmlString === false) return [];
        return $this->getProductsFromHtml($htmlString, (int)$category_id);
    }

}
