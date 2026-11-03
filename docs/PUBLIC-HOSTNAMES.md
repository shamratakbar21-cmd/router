# Public and authenticated hostname routing

The DNS records remain Cloudflare-proxied. Dedicated origin HTTPS virtual hosts and a separate Let's Encrypt certificate cover the five aliases; the main website and admin certificates remain unchanged. Renewal uses the persistent HTTP webroot and existing Certbot timer.

| Host | Destination | Access |
|---|---|---|
| nurrouter.com / www.nurrouter.com | Current website | Public |
| docs.nurrouter.com | nurrouter.com/docs | Public; /install also resolves to the install guide |
| download.nurrouter.com | nurrouter.com/downloads | Public; versioned /releases/ paths redirect to canonical files |
| updates.nurrouter.com | nurrouter.com/release | Public release notes; automatic firmware-update API is unsupported |
| admin.nurrouter.com | WinControl | Application login and authorization |
| cloud.nurrouter.com | admin.nurrouter.com | Application login and authorization |
| license.nurrouter.com | admin.nurrouter.com/licensing | Authenticated licensing administration |

The aliases accept GET/HEAD entry-point navigation and reject other methods. Mutations use only the implemented authenticated canonical application endpoints, with CSRF and existing rate limits. Public licensing information remains at nurrouter.com/licensing. No Cloudflare Access policy or DNS proxy setting for another application was changed. No new cloud product, anonymous activation API or automatic appliance updater is claimed.

The reviewed origin configuration is management/deploy/ubuntu/nginx/public-aliases.conf. Private key material is not included. The configuration was validated before reload, all five origins and public redirects were checked with certificate verification, and anonymous administrative requests still return 401.
