# Statics Merger

A composer plugin aimed to simplify the workflow between the frontend and backend development teams. Static repositories that the frontend team use are added as a composer dependency to the project as type ```static```.

The plugin hooks onto two composer commands ```install``` and ```update``` in which on completion will symlink all static packages as defined in their ```composer.json``` file.

## Installation

This module is installable via ```Composer```. If you have used the Magento Skeleton as a base module then you can just require this project and all the rest is done for you.

```sh
$ cd project-root
$ ./composer.phar require "wearejh/statics-merger"
```

### Upgrading 1.x to 2.x ?

It's recommended to first run `composer update statics-merger --no-plugins` after changing your composer.json and then run a `composer update nothing` to map the new configuration.

*__Note:__ Depending on the configuration changes you may also have to manually cleanup any remaining symlinks from the old mappings*


## Usage

### Statics project

*If the `composer.json` file is not already there, create one with relevant information and commit it to the repo.*

For this to work the statics repository requires the `composer.json` to have the `type` set to `static`.

#### Example Static Composer.json

```json
{
    "name": "wearejh/{project-name}-static",
    "type": "static",
    "description": "Main theme for {project-name}",
    "keywords": ["jh", "statics"],
    "authors": [
        {
            "name": "JH",
            "email": "hello@wearejh.com",
            "homepage": "http://www.wearejh.com"
        }
    ]
}
```


### Magento project

Within your projects `composer.json` you will need to ensure you have a few configurations set up.

In your `require` you will need to add any statics that you want and if private also add the repo.

*__Note:__ It's great at handling multiple static repositories* :thumbsup:

```json
"require": {
    "wearejh/{project-name}-static": "dev-master"
},
"repositories": [
    {
        "type": "git",
		"url": "git@jh.git.beanstalkapp.com:/jh/{project-name}-static.git"
    }
]
```

In your ```extra``` you need the ```magento-root-dir``` set correctly and have defined the ```static-map``` for each static repository.

```json
"extra":{
    "magento-root-dir": "htdocs/",
    "static-map" : {
        "wearejh/{project-name}-static": {
            "package/theme": [
                {
                    "src": "public/assets",
                    "dest": "assets"
                },
                {
                    "src": "public/assets/img/favicon*",
                    "dest": "/"
                },
                {
                    "src": "assets/images/catalog",
                    "dest": "images/catalog"
                }
            ]
        }
    }
}
```

The first key is the name of the repository which you have used in the `require` section, while inside there each key is the `package/theme` in which the example would map to `skin/frontend/package/theme` within your `magento-root-dir.

The `package/theme` array contains several objects defining the `src and dest of the files. The src` value is relevant to the __root__ of the __statics__ repository while the `dest` is relevant to the `package/theme` defined in the __Magento project__ such as `skin/frontend/package/theme/` within your `magento-root-dir`.

__Need to map a static repo to more than 1 package or theme?__ No problem just add another `package/theme` array to your repos mappings, of course make sure you use a different name to any others to avoid overwriting.

#### Valid Mappings

*__Note:__ Globs require the `dest` to be a folder and not a file, whereas files and directories need to point to there corresponding full path which allows you to rename them if required. If you leave the `dest` blank on a glob it will map to the same source directory structure within your `package/theme`*

##### Files

Link an image into a different directory structure and rename

```json
{
    "src": "public/assets/img/awesome/cake.gif",
    "dest": "images/newcake.gif"
}
```

##### Directories

Linking a whole directory keeping all sub-dirs & files

```json
{
    "src": "public/assets",
    "dest": "assets"
}
```

##### Globs

You can also use globs which makes it pretty awesome! A great use case for this is favicons where you could have multiple at different resolutions with a set naming convention. To target them all you would simply use `favicon*` like in the default example below.

All favicons to root dir `skin/frontend/package/theme/`

```json
{
    "src": "favicon*",
    "dest": "/"
}
```

#### Final Notes

* Use tags to explicitly pull in the static repositories
* Don't forget to add the `package/theme` dir to your `.gitignore` otherwise you will add the statics files to the Magento repo, and everyone will hate you
* You can amend statics directly from the `vendor` dir and push straight to the main repo, WIN!
* Have fun !! :smile:

## Problems?

If you find any problems or edge cases which may need to be accounted for within this composer plugin just open up an issue with as much detail as possible so it can be recreated.

## Running Tests

```sh
$ cd vendor/wearejh/statics-merger
$ php composer.phar install
$ ./vendor/bin/phpunit
```
