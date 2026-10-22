#!/usr/bin/env bash
# Entrypoint of the cloudflare-fake service. Runs as root only long enough
# to mint a throwaway CA and a leaf for api.cloudflare.com on tmpfs, publish
# the CA certificate (never its key) to the volume the web container reads,
# and hand the control directory to www-data; then serves as www-data.
# Nothing here touches the host: a fresh CA exists for each container start.

set -euo pipefail

tls=/run/cf-fake-tls
control=/run/cf-fake
ca_out=/run/cf-smoke-ca

ca_dir="$(mktemp -d)"
trap 'rm -rf "${ca_dir}"' EXIT

# Its own config, so the system openssl.cnf cannot add a second
# basicConstraints, which makes verifiers reject the CA.
printf '%s\n' \
	'[req]' \
	'distinguished_name = dn' \
	'x509_extensions = v3_ca' \
	'prompt = no' \
	'[dn]' \
	'CN = cf-smoke test CA' \
	'[v3_ca]' \
	'basicConstraints = critical,CA:TRUE,pathlen:0' \
	'keyUsage = critical,keyCertSign,cRLSign' \
	'subjectKeyIdentifier = hash' >"${ca_dir}/ca.cnf"

openssl req -x509 -config "${ca_dir}/ca.cnf" -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 2 \
	-keyout "${ca_dir}/ca.key" -out "${ca_dir}/ca.crt" 2>/dev/null

openssl req -new -config "${ca_dir}/ca.cnf" -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes \
	-subj '/CN=api.cloudflare.com' \
	-keyout "${tls}/leaf.key" -out "${ca_dir}/leaf.csr" 2>/dev/null

# Only api.cloudflare.com: the smoke checks that any other name is refused.
printf '%s\n' \
	'basicConstraints=critical,CA:FALSE' \
	'keyUsage=critical,digitalSignature' \
	'extendedKeyUsage=serverAuth' \
	'subjectKeyIdentifier=hash' \
	'authorityKeyIdentifier=keyid' \
	'subjectAltName=DNS:api.cloudflare.com' >"${ca_dir}/leaf.ext"

openssl x509 -req -days 2 -in "${ca_dir}/leaf.csr" \
	-CA "${ca_dir}/ca.crt" -CAkey "${ca_dir}/ca.key" -CAcreateserial \
	-extfile "${ca_dir}/leaf.ext" -out "${tls}/leaf.crt" 2>/dev/null

chown www-data:www-data "${tls}" "${tls}/leaf.key" "${tls}/leaf.crt"
chmod 0400 "${tls}/leaf.key"

# Renamed into place so the web container never installs a half-written file.
install -m 0644 "${ca_dir}/ca.crt" "${ca_out}/ca.crt.tmp"
mv "${ca_out}/ca.crt.tmp" "${ca_out}/ca.crt"

chown www-data:www-data "${control}"
chmod 0700 "${control}"
runuser -u www-data -- touch "${control}/requests.log"

rm -rf "${ca_dir}"
trap - EXIT

# setpriv execs in place, so no root parent outlives the setup above.
exec setpriv --reuid=www-data --regid=www-data --init-groups -- php /opt/fake-cloudflare/server.php 443 \
	"${tls}/leaf.crt" "${tls}/leaf.key" /opt/fixtures "${control}"
