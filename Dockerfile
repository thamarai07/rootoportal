FROM php:8.2-apache

# Enable Apache modules
RUN a2enmod rewrite

# Copy all files to Apache web root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]