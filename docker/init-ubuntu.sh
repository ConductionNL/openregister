apt update;
apt install npm composer php-gd php-zip -y

cd /home/ubuntu/app
npm i
npm run dev

composer install