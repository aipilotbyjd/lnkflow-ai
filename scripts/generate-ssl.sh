#!/bin/bash
# Generate self-signed SSL certificates for testing
# For production, use Let's Encrypt or your certificate provider

set -e

BLUE='\033[0;34m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${BLUE}Generating self-signed SSL certificates...${NC}"
echo ""
echo -e "${YELLOW}⚠ WARNING: These are for TESTING ONLY${NC}"
echo -e "${YELLOW}For production, use Let's Encrypt or a trusted CA${NC}"
echo ""

SSL_DIR="infra/nginx/ssl"
mkdir -p "$SSL_DIR"

# Generate private key
openssl genrsa -out "$SSL_DIR/privkey.pem" 2048

# Generate certificate
openssl req -new -x509 -key "$SSL_DIR/privkey.pem" \
    -out "$SSL_DIR/fullchain.pem" \
    -days 365 \
    -subj "/C=US/ST=State/L=City/O=LinkFlow/CN=linkflow.local" \
    -addext "subjectAltName=DNS:linkflow.local,DNS:api.linkflow.local,DNS:engine.linkflow.local,DNS:localhost"

# Set proper permissions
chmod 600 "$SSL_DIR/privkey.pem"
chmod 644 "$SSL_DIR/fullchain.pem"

echo ""
echo -e "${GREEN}✓ Certificates generated successfully${NC}"
echo ""
echo "Files created:"
echo "  • $SSL_DIR/fullchain.pem"
echo "  • $SSL_DIR/privkey.pem"
echo ""
echo "Valid for: 365 days"
echo "Domains: linkflow.local, api.linkflow.local, engine.linkflow.local, localhost"
echo ""
