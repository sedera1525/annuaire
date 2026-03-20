1. Objectif du projet
Mettre en place un backend capable de générer automatiquement des fiches entreprises à partir des données publiques fournies sur fichier CSV (4,7M), puis de les rendre accessibles via une API afin que le site front-end puisse afficher les pages entreprises.
Le système devra permettre :
- la génération de contenus courts pour chaque entreprise
- la consultation des fiches via API
- la modification ou suppression d’une fiche entreprise via un compte admin et un compte client. Le client aura un espace client pour modifier gratuitement sa page entreprises et s’abonner pour obtenir l’accès à un certain nombre de questions (pour y répondre). Le produit sera créé par Jesse sur Woocommerce et Stripe + notifications d’achats, facture et suivi automatisé de la commande.
2. Source des données
Les données entreprises proviennent d’un fichier CSV fourni par Compliance Expertise Group.
Les informations principales utilisées seront :
- nom de l’entreprise
- adresse
- code postal
- ville
- numéro de téléphone (si disponible)
- site web (si disponible)
- secteur / activité
- nom du dirigeant.
Les données seront fournies sous forme de fichier CSV.
3. Génération des contenus
Pour chaque entreprise :
- génération de 12 à 18 questions et réponses, (1 phrase par réponse), basé sur un prompt unique
- génération via OpenAI (ChatGPT 4 ou 5.nano) avec compte fourni par le client
Afin de faciliter la prod, Jesse mettra en place un système asyschrone (CPU fourni par le client). Cible du timing de la prod : minimum 20000 fiches entreprises par jour : c’est ce que l’on fait actuellement pour un prompt plus compliqué sur avis-pros.com.
Le texte généré servira de description de base de la fiche entreprise.
4. Infrastructure technique
Le backend sera déployé sur :
- Serveur CPU OVH Rise (fourni par le client)
- environnement Docker
Pour optimiser la génération des contenus :
- mise en place d’un traitement asynchrone
- possibilité de parallélisation des requêtes
5. API Backend
Le backend devra permettre :
Consultation des fiches entreprises
Endpoints API pour :
- récupérer une fiche entreprise
- récupérer une liste d’entreprises
- recherche par ville ou métier
Ces données seront consommées par le front-end du site.
6. Gestion des fiches
Chaque fiche entreprise devra pouvoir :
- être modifiée
- être supprimée
La structure permettra également de créer des liens entre :
- entreprises d’un même secteur
- entreprises d’une même ville
Ces regroupements serviront notamment pour les pages SEO futures.
7. Intégration front-end
Le front-end du site utilisera l’API backend pour :
- afficher les pages entreprises
- alimenter le moteur de recherche métiers / villes
Le moteur de recherche et la base des 35 000 villes seront installés par l’équipe du client.
8. l'application couvre :
- ce qui a été précisé ci-dessus
- mise en place du backend
- intégration génération texte via OpenAI
- traitement asynchrone simple : minimum 6 têtes
- API pour consultation des fiches
Les fonctionnalités SEO avancées (pages sectorielles, comparatives, top entreprises) pourront faire l’objet d’évolutions ultérieures.
Il sera mis en relation avec Rado, webmaster de Compliance Expertise Group, pour l’obtention, notamment, de l’acces au sous-domaine, du moteur de recherche et du fichier des 35000 villes francaises. Erick fournira le CPU et le compte OpenAI.
9. Validation
Une fois ce document validé :
- le client transmettra les accès serveur
- le développement pourra démarrer.

Existant : 

http://entreprises-france.topsocietes.com/wp-login.php
ressources1@gmail.com
kEB(^RCmEuEKoUMkNsKHb$$8n

serveur
https://auth.eu.ovhcloud.com/signin/
Identifiant : 5551-0647-70/devweb
ressources1@gmail.com
6UIqycAB$QOBnLKeMK

un clé api open ai