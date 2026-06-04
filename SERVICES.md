## SERVICES.md

Purpose of this file is to list or services that are used in the project. 
It can be used as a reference for developers to understand what services are available and how to use them.
It is also a relevant list of paid subsciptions that the project relies on, 
which is useful for budgeting and cost management.

- [Fly.io](https://fly.io) — hosting and deployment platform for the application
- [Anthropic](https://anthropic.com) — provider of the Claude AI model used for generating maintenance suggestions
- [GitHub](https://github.com) — code hosting and version control for the project, actions for CI/CD, and issue tracking
- [Exa.ai](https://exa.ai) — AI-powered search API
- [Context7](https://context7.com) — live, agent-readable library documentation (used during planning and implementation to fetch up-to-date docs for Laravel, Livewire, Prism, etc.)
- **Email delivery** *(not yet configured)* — required for user email verification; dashboard is gated behind `middleware(['auth', 'verified'])` so users cannot reach it without a delivered verification link. Local dev: [Mailpit](https://mailpit.axllent.org/) (ships with Laravel Herd, or run via Docker). Production: a transactional email provider (Resend, Mailgun, Postmark, etc.). Configure via `MAIL_*` env vars in Laravel.
