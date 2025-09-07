import requests
import openpyxl
import time

# ✅ Shopid hej.organic yang sudah benar
SHOPID = 117783624

# Headers biar tidak diblokir
HEADERS = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36",
    "Referer": f"https://shopee.co.id/hej.organic",
}

def get_all_products(shopid, limit=100):
    products = []
    offset = 0

    while True:
        url = f"https://shopee.co.id/api/v4/recommend/recommend?bundle=shop_page_category_tab_main&item_card=2&limit={limit}&offset={offset}&shopid={shopid}"
        res = requests.get(url, headers=HEADERS)

        if res.status_code != 200:
            print(f"❌ Gagal ambil data produk di offset {offset}. Status code: {res.status_code}")
            break

        data = res.json().get("data", {}).get("sections", [])
        if not data:
            break

        items = data[0].get("data", {}).get("item", [])
        if not items:
            break

        for item in items:
            product = {
                "Nama Produk": item["name"],
                "Harga (IDR)": item["price"] / 100000,
                "Stok": item.get("stock", 0),
                "Jumlah Terjual": item.get("historical_sold", 0),
                "Link Produk": f"https://shopee.co.id/{item['name'].replace(' ', '-')}-i.{shopid}.{item['itemid']}",
                "Gambar Produk": f"https://down-vn.img.susercontent.com/file/{item['image']}"
            }
            products.append(product)

        offset += limit
        time.sleep(1)

    return products

def save_to_excel(products, filename="produk_shopee_hejorganic.xlsx"):
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.append(list(products[0].keys()))  # Buat header kolom

    for product in products:
        ws.append(list(product.values()))

    wb.save(filename)
    print(f"✅ Data produk berhasil disimpan ke {filename}")

if __name__ == "__main__":
    products = get_all_products(SHOPID)
    if products:
        print(f"📦 Ditemukan {len(products)} produk.")
        save_to_excel(products)
    else:
        print("⚠️ Tidak ada produk ditemukan.")
