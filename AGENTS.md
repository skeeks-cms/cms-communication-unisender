# CMS Communication Unisender Go development

Read README.md and `skeeks/cms-communication` AGENTS.md and ARCHITECTURE.md
(stage 4, item 4C) before changing this package. Follow the active
shared-vendor skeeks-cms-package-development skill.

This package speaks the Unisender Go Web API only: `UnisenderDriver`
(`mail\MailingServiceInterface`: send-only, one `email/send` call per letter
with our metadata), `WebhookReceiver` (signature, parsing), `UnisenderCallbacks`
(store the verified body, apply through `services\DeliveryEvents`), the campaign
channel `campaign\UnisenderCampaignChannel` (`MailingPolicy::MODE_SERVICE`) and
`UnisenderEventDump`. Delivery states, the stop list, opt-outs, campaigns and
the letter belong to `cms-communication`; never write them here directly.

The API key is a connection secret: never log, print, return or put it into
an exception, a job payload, a test or a file. Unknown send outcomes (5xx,
network) are never resent; only 429 waits in the queue. "Sent" means the
service took the letter; "delivered" comes only from its events.
