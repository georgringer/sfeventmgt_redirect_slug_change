[![TYPO3 13](https://img.shields.io/badge/TYPO3-13-orange.svg)](https://get.typo3.org/version/13)
[![Donate](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.me/GeorgRinger/20)


# TYPO3 Extension `sfeventmgt_redirect_slug_change`

This extension generates redirects for records of EXT:sf_event_mgt if the slug is changed:
- Detail view
- Registration view

## Setup

Install the extension just as any other extension as well.

- Use `composer req georgringer/sfeventmgt-redirect-slug-change`

## Configuration

Configuration within the `config.yaml` of the site configuration:

```yaml
redirectsSfeventmgt:
 detailPageId: 123
 registrationPageId: 456
 # Automatically create redirects for event records with a modified slug (works only in LIVE workspace)
 # (default: true)
 autoCreateRedirects: true
 # Time To Live in days for redirect records to be created - `0` disables TTL, no expiration
 # (default: 0)
 redirectTTL: 30
 # HTTP status code for the redirect, see
 # https://developer.mozilla.org/en-US/docs/Web/HTTP/Redirections#Temporary_redirections
 # (default: 307)
 httpStatusCode: 307
```

## Say thanks

If you are using this extension in one of your projects or for a client, please think about sponsoring this extension.

- Paypal: https://www.paypal.me/GeorgRinger/20
- *or* contact me if you need an invoice

**Thanks!**
