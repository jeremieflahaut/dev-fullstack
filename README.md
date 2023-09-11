[![Tests](https://github.com/jeremieflahaut/dev-fullstack/actions/workflows/test.yml/badge.svg)](https://github.com/jeremieflahaut/dev-fullstack/actions/workflows/test.yml)

# My Personal Blog

Welcome to my personal blog! This GitHub repository contains the source code of my blog, as well as the articles I've written.

## Technologies Used

- Programming Language: Php8.2
- Framework: Laravel
- Database: Mysql

## Features

- Display of articles
- Article categorization system

## Continuous Integration and Deployment (CI/CD)

I've implemented CI/CD using GitHub Actions to automate the testing and deployment processes. This ensures that the codebase is continuously integrated, tested, and deployed to production when changes are pushed to the repository.

The CI/CD pipeline includes the following steps:

- **tests:** The code is automatically built, and tests are run to ensure its integrity.

- **deploy:** Once the code passes all tests, it is automatically deployed to the production server.

By automating these processes, we aim to maintain a stable and reliable blog while streamlining the development workflow.

Feel free to check out the GitHub Actions workflows in the [`.github/workflows`](.github/workflows) directory for more details on the setup.
