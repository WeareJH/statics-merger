# Statics Merger

A composer plugin aimed to simplify the workflow between the frontend and backend development teams. Static repositories that the frontend team use are added as a composer dependency to the project as type ```static```. 

The plugin hooks onto two composer commands ```install``` and ```update``` in which on completion will symlink all static packages as defined in their ```composer.json``` file.

## Installation

This module is installable via ```Composer```. If you have used the Magento Skeleton as a base module then you can just require this project and all the rest is done for you.

```
$ cd project-root
$ ./composer.phar require "jhhello/statics-merger" 
```

*__Note:__ As these repositories are currently private and not available via a public pakage list like Packagist Or Firegento you need to add the repository to the projects composer.json before you require the project.*

```
"repositories": [
    {
        "type": "git",
        "url": "git@bitbucket.org:jhhello/statics-merger.git"
    }
]
```

## Configuration

### Magento project

Within your projects ```composer.json``` you will need to ensure you have a few configurations set up. 

In your ```require``` you will need to add any statics that you want and if private also add the repo. 

```
"require": {
    "jhhello/cake-dec-static": "dev-master"
},
"repositories": [
    {
        "type": "git",
		"url": "git@jh.git.beanstalkapp.com:/jh/cake-dec-static.git"
    }
]
```

While in your ```extra``` you need the ```magento-root-dir``` set correctly and have defined the ```static-maps``` for each static repository.

```
"extra":{
    "magento-root-dir": "htdocs/",
    "static-maps" : {
        "jhhello/cake-dec-static": "cake/default"
    }
}
```

The key is the name of the repository which you have used in the ```require``` section, while the value is the ```theme/package``` and the example would map to ```skin/frontend/cake/default``` within your ```magento-root-dir```.

### Statics project

*__Note:__ If the ```composer.json``` file is not already there, create one with relevant information and commit it to the repo.*

For this to work the statics repository requires the ```composer.json``` to have the ```type``` set to ```static```.

Extra file configuration can be set using the ```files``` array within the ```extra``` section in the ```composer.json``` file.

The ```files``` array contains several objects defining the ```src``` and ```dest``` of the files. The ```src``` value is relevant to the __root__ of the __statics__ repository while the ```dest``` is relevant to the ```theme/package``` defined in the __Magento project__ such as ```skin/frontend/cake/default/``` within your ```magento-root-dir```.

You can also use globs which makes it pretty awesome! A great use case for this is favicons where you could have multiple at different resolutions with a set naming convention. To target them all you would simply use ```favicon*``` like in the default example below.

*__Note:__ Globs require the ```dest``` to be a folder and not a file, whereas files and directories need to point to there corresponding path which allows you to rename them if required.*

The set defaults are below for a quick copy and paste.

```
"type": "static",
"extra": {
    "files": [
        {
            "src": "favicon*",
            "dest": ""
        },
        {
            "src": "assets/images/catalog",
            "dest": "images/catalog"
        }
    ]
}
```

*__Note:__ By default the assets folder is done for you, this is used for any extra files such as favicons and catalog placeholder images etc but is not required.*

## Problems?

If you find any problems or edge cases which may need to be accounted for within this composer plugin just open up an issue with as much detail as possible so it can be recreated. 

## Running Tests

```
$ cd vendor/jhhello/statics-merger
$ php composer.phar install
$ ./vendor/bin/phpunit
```