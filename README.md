# FOSSBilling Registrar Connect

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

A FOSSBilling registrar module for working with multiple domain registrars through one consistent API.

## Registrar Support

| Registrar | Status | Notes |
|-----------|--------|-------|
| **Name.com** | beta | Test and production environments. |
| OpenSRS | alpha | Test and production environments. |
| GoDaddy | alpha | Test and production environments. Requires API key, API secret and registration consent IP. |
| NameSilo | alpha | Production only. |
| Dynadot | alpha | Test and production environments. |
| Namecheap | alpha | Test and production environments. Requires API user, API key and whitelisted server IPv4. |
| OpusDNS | alpha | Test and production environments. |

## Installation

1. Use our **[Module Customizer Tool](https://namingo.org/foss-module/)** to generate a fine-tuned registrar module specifically for your registrar, then extract the **generated archive** into `/tmp`.

2. From the extracted archive in `/tmp`, move the `namingo/` directory into your FOSSBilling/Namingo Registrar installation directory (for example `/var/www/fossbilling` or `/var/www`, depending on where it is installed). Move the main module file `Connect.php` into `[FOSSBilling_path]/library/Registrar/Adapter`.

If this is not your first registrar module, you may skip copying the `namingo/` directory, as it is shared between modules.

3. Within FOSSBilling, go to **System -> Domain Registration -> New Domain Registrar** and activate the new domain registrar.

4. Go to the **Registrars** tab in the admin panel and select your registrar module. Enter your registrar connection details.

5. Add a new Top Level Domain (TLD) using your module from the "**New Top Level Domain**" tab. Make sure to configure all necessary details, such as pricing, within this tab.

## Upgrade

- Before upgrading, note your current module settings.
- Download the updated module and repeat the installation steps to replace the existing files.
- After upgrading, verify that your module settings are still correct.

## Troubleshooting

1. **Multiple modules conflict**
   - FOSSBilling does not allow multiple registrar modules that share the same internal function names.
   - If you are connecting to more than one registry, you must generate each module using the Module Customizer Tool to ensure unique module names.
   - Do not manually duplicate or rename module files, as this may cause function collisions or unexpected behavior.

### Need More Help?

If the steps above do not resolve your issue, check the FOSSBilling logs and enable **Error Reporting** in the admin panel to display detailed error messages.

## Support

Your feedback and inquiries are invaluable to Namingo's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).

- **Discord**: Or chat with us on our [Discord](https://discord.gg/97R9VCrWgc) channel.
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/fossbilling-registrar-connect/issues) section of our GitHub repository.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## Support This Project

If you find FOSSBilling Registrar Connect useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

FOSSBilling Registrar Connect is licensed under the MIT License.