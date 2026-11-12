apt update;
apt install npm composer php-gd php-zip -y

cd /home/ubuntu/app
npm ci
npm run dev

composer install