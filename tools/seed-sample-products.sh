#!/bin/bash
#
# Seed a local WooCommerce store with sample products of varying quality.
# Run after wp-env start: ./tools/seed-sample-products.sh
#
# Creates products with deliberately different completeness levels
# so the readiness scoring produces interesting results.

set -e

echo "🏪 Seeding sample products..."

# Create categories.
npx wp-env run cli -- wp wc product_cat create --name="Clothing" --slug="clothing" --user=admin 2>/dev/null || true
npx wp-env run cli -- wp wc product_cat create --name="T-Shirts" --slug="t-shirts" --parent=1 --user=admin 2>/dev/null || true
npx wp-env run cli -- wp wc product_cat create --name="Electronics" --slug="electronics" --user=admin 2>/dev/null || true
npx wp-env run cli -- wp wc product_cat create --name="Home & Garden" --slug="home-garden" --user=admin 2>/dev/null || true

# Create product attributes.
npx wp-env run cli -- wp wc product_attribute create --name="Size" --slug="size" --user=admin 2>/dev/null || true
npx wp-env run cli -- wp wc product_attribute create --name="Colour" --slug="colour" --user=admin 2>/dev/null || true
npx wp-env run cli -- wp wc product_attribute create --name="Material" --slug="material" --user=admin 2>/dev/null || true

# Product 1: Well-documented product (should score high).
npx wp-env run cli -- wp wc product create \
  --name="Premium Organic Cotton T-Shirt" \
  --type=simple \
  --regular_price="29.99" \
  --description="<p>Our Premium Organic Cotton T-Shirt is made from 100% GOTS-certified organic cotton, sourced from sustainable farms in India. The fabric is pre-shrunk and garment-dyed for lasting colour and fit.</p><p>Features a classic crew neck, reinforced shoulder seams, and a relaxed fit that suits all body types. The 180gsm weight makes it perfect for year-round wear — substantial enough for cooler days but breathable in summer.</p><p>Available in 6 colours and sizes XS to 3XL. Machine washable at 30°C. Tumble dry low.</p>" \
  --short_description="100% organic cotton t-shirt. Classic fit, 180gsm weight, available in 6 colours. GOTS certified." \
  --categories='[{"id":2}]' \
  --stock_quantity=150 \
  --manage_stock=true \
  --weight="0.2" \
  --tags='[{"name":"organic"},{"name":"sustainable"},{"name":"cotton"}]' \
  --user=admin 2>/dev/null || true

# Product 2: Sparse product (should score low).
npx wp-env run cli -- wp wc product create \
  --name="Blue Widget" \
  --type=simple \
  --regular_price="9.99" \
  --description="A blue widget." \
  --user=admin 2>/dev/null || true

# Product 3: Medium quality.
npx wp-env run cli -- wp wc product create \
  --name="Wireless Bluetooth Speaker" \
  --type=simple \
  --regular_price="49.99" \
  --sale_price="39.99" \
  --description="<p>Portable wireless speaker with Bluetooth 5.3 connectivity. Delivers rich, clear sound with deep bass from a compact design that fits in your bag.</p><p>12-hour battery life. IPX5 water resistant. USB-C charging.</p>" \
  --short_description="Portable Bluetooth 5.3 speaker. 12-hour battery, IPX5 water resistant." \
  --categories='[{"id":3}]' \
  --stock_quantity=45 \
  --manage_stock=true \
  --weight="0.35" \
  --user=admin 2>/dev/null || true

# Product 4: Missing description entirely.
npx wp-env run cli -- wp wc product create \
  --name="Garden Trowel" \
  --type=simple \
  --regular_price="12.50" \
  --categories='[{"id":4}]' \
  --stock_quantity=200 \
  --manage_stock=true \
  --user=admin 2>/dev/null || true

# Product 5: Out of stock, no image.
npx wp-env run cli -- wp wc product create \
  --name="Vintage Ceramic Mug" \
  --type=simple \
  --regular_price="18.00" \
  --description="Hand-thrown ceramic mug. 350ml capacity. Dishwasher safe." \
  --categories='[{"id":4}]' \
  --stock_status="outofstock" \
  --user=admin 2>/dev/null || true

# Product 6: No price set.
npx wp-env run cli -- wp wc product create \
  --name="Custom Leather Journal" \
  --type=simple \
  --description="<p>Hand-stitched leather journal with 200 pages of acid-free paper. Available in brown, black, and tan.</p>" \
  --user=admin 2>/dev/null || true

# Create a shipping policy page.
npx wp-env run cli -- wp post create \
  --post_type=page \
  --post_title="Shipping Policy" \
  --post_name="shipping-policy" \
  --post_status=publish \
  --post_content="<h2>UK Delivery</h2><p>Standard delivery takes 3-5 working days. Express delivery is available for an additional charge and arrives within 1-2 working days.</p><h2>International Delivery</h2><p>We ship to most countries worldwide. International delivery typically takes 7-14 working days depending on destination.</p>" \
  --user=admin 2>/dev/null || true

# Create a returns policy page.
npx wp-env run cli -- wp post create \
  --post_type=page \
  --post_title="Returns Policy" \
  --post_name="refund-returns" \
  --post_status=publish \
  --post_content="<h2>Returns</h2><p>You may return most items within 30 days of delivery for a full refund. Items must be unused and in original packaging.</p><h2>Exchanges</h2><p>We offer free exchanges on clothing items for different sizes within 14 days.</p>" \
  --user=admin 2>/dev/null || true

# Generate WC REST API keys.
echo ""
echo "🔑 Creating REST API keys..."
npx wp-env run cli -- wp wc customer_key create \
  --user=admin \
  --description="Woo MCP Server" \
  --permissions=read 2>/dev/null || true

echo ""
echo "✅ Sample data seeded!"
echo ""
echo "Next steps:"
echo "  1. Copy the consumer key and secret from above"
echo "  2. Enable the WC core MCP feature:"
echo "     npx @wordpress/env run cli -- wp option update woocommerce_feature_mcp_integration_enabled yes"
echo "  3. Point your MCP client at http://localhost:8888/wp-json/woocommerce/mcp"
echo "     with header: X-MCP-API-Key: ck_...:cs_..."
