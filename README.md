<p align="center"><a href="https://bravo.ondaq.com" target="_blank"><img src="https://ondaq.com/assets/app/img/logo.0cc5475f.png" width="400"></a></p>



## About Ondaq

Ondaq is a web application built in Laravel and VueJs



## Ondaq Setup

Clone the Git reposity by the following link 
```console
git clone https://Mehran330@bitbucket.org/Ondsolutions/bravo.git
```

## Now install all the dependencies of the project
```console
Composer Install
```

## Now we need to instal the node_modules 

Got to the following `resources/frontend/app` path and run the below command 
```console
npm Install
```

## Create database called - `ondaq` 

## Create `.env` file by copying `.env.example` file

## Generate Artisan Key (If needed) -
```console
php artisan key:generate
```

## Migrate Database with seeder -
```console
php artisan migrate --seed
```

## Run Laravel Project -
```console
php artisan serve
```

Now Run Vuejs -
```console
npm run build
```

## Now Go to Browser and hit url -
```console
http://localhost:8080
```

## You are good to go, Thank me later.

Author: Mehran Shah 
Date: 03-March-2022
