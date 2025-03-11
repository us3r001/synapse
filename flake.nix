{
  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
    flake-parts.url = "github:hercules-ci/flake-parts";
    snow-blower.url = "github:use-the-fork/snow-blower";
  };

  outputs = inputs: let
    pkgs = import inputs.nixpkgs {
      # Add overlay here to inject the mkdocs-material and extensions plugin
      nixpkgs.overlays = self: super: {
        mkdocs = super.mkdocs.override {
          propogatedBuildInputs =
            [super.mkdocs.propogatedBuildInputs]
            ++ pkgs.python312Packages.mkdocs-material
            ++ pkgs.python312Packages.mkdocs-material-extensions;
        };
      };
    };
  in
    inputs.snow-blower.mkSnowBlower {
      inherit inputs;
      perSystem = {
        config,
        lib,
        pkgs,
        ...
      }: let
        serv = config.snow-blower.services;
        lang = config.snow-blower.languages;

        # Refrences PHP and Composer later in this config.
        composer = "${lang.php.packages.composer}/bin/composer";
        php = "${lang.php.package}/bin/php";

        publicKeys = [
          "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOSE69dmDxQ/UJ8k+8CL3lzc/PyJXXO/2aCcYQOjkTW+ " #Greg Home Machine
          "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBheo89VICojUMhqeSykFVEBoO0R+kKwlFxkS4DZ+NER" #Greg
        ];

        envKeys = builtins.attrNames config.snow-blower.env;
        unsetEnv = builtins.concatStringsSep "\n" (
          map (key: "unset ${key}") envKeys
        );
      in {
        snow-blower = {
          paths.src = ./.;

          # Conviance scripts
          scripts = {
            pf.exec = ''
              ${unsetEnv}
              ./vendor/bin/pest --filter "$@"
            '';
            p.exec = ''
              ${unsetEnv}
              ./vendor/bin/pest
            '';
            coverage.exec = ''
               export XDEBUG_MODE=coverage
              ./vendor/bin/pest --coverage
              unset XDEBUG_MODE
            '';

            # swap a and artisan commands for testbench
            a.exec = ''
              ${unsetEnv}
              ./vendor/bin/testbench "$@"
            '';
            artisan.exec = ''
              ${unsetEnv}
              ./vendor/bin/testbench "$@"
            '';
          };

          processes = {
          };

          languages = {
            # the required version of PHP for this project.
            php = {
              enable = true;
              version = "8.2";
              extensions = ["grpc" "redis" "imagick" "memcached" "xdebug"];
              ini = ''
                memory_limit = 5G
                max_execution_time = 90
              '';
            };

            javascript.enable = true;
            javascript.npm.enable = true;
          };

          services = {
            aider.enable = true;
          };

          integrations = {
            #secrets
            agenix = {
              enable = true;
              secrets = {
                ".env" = {inherit publicKeys;};
              };
            };

            #Creates Changelogs based on commits
            git-cliff.enable = true;

            treefmt = {
              settings.formatter = {
                "pint" = {
                  command = "${composer}";
                  options = [
                    "lint"
                  ];
                  includes = ["*.php"];
                };
                "refactor-file" = {
                  command = "${composer}";
                  options = [
                    "refactor-file"
                    "--"
                    "--debug"
                    "process"
                  ];
                  includes = ["*.php"];
                };
              };
              programs = {
                #Nix Formater
                alejandra.enable = true;

                #Format Markdown files.
                mdformat.enable = true;

                #JS / CSS Formatting.
                prettier = {
                  enable = true;
                  settings = {
                    trailingComma = "es5";
                    semi = true;
                    singleQuote = true;
                    jsxSingleQuote = true;
                    bracketSpacing = true;
                    printWidth = 80;
                    tabWidth = 2;
                    endOfLine = "lf";
                  };
                };
              };
            };

            # Guess what this does. Go ahead Guess.
            git-hooks.hooks = {
              # run formatting on files that are being commited
              treefmt.enable = true;

              #lets make sure there are no keys in the repo
              detect-private-keys.enable = true;

              #fix line endings.
              mixed-line-endings.enable = true;
            };
          };

          shell.interactive = [
            ''
              if [[ ! -d vendor ]]; then
                  ${composer} install
              fi
            ''
          ];
        };
      };
    };
}
