# bilet-satin-alma

İlk olarak projemin feature complete olmaması içler acısı. 20 günlük sürenin son 1,5 gününde yapmaya çalıştığım için oldu bu 😭. Feature complete olmadığı için hardening ve security audit işine hiç giremedim. File upload açıklarını engellemeye çalışmak için logoları sadece admin tarafından değiştirilebilir yaptım. 

Kodun %99'unu gemini ai pro yazdı ve akla karayı seçtirdi. Garip gurup halisinasyonları düzeltmek için çok uğraştım. Belki de 2.5-flash kullandığım içindi ama 2.5-pro'da bariz fark göremedim.

Projeye başlamayı hiç istemiyordum çünkü apache/nginx, php, sql ve docker kullanmayı hiç bilmediğim aletler idi. 

 - php'yi 15 trilyon yıl öncesinin dili olmasına ve çok itici bulmama rağmen az da olsa ön yargım kırıldı. Ne için kullanıldığını ve çözdüğü sorunu biraz daha iyi anladım. Ama hala php kullanmayı bırakıp daha modern bir çözüme geçilmesi taraftarıyım.

 - web server seçimi için nginx ve apache arasında çok gelip gittim. docker kullanmasını öğrenirken, web servisinin kullandığı image her iterasyonda değişti. Sonunda en basit çözümü php:nginx imajına sqlite3 kurmak olduğuna karar verdim.

 - Docker kullanımış olmamız çok hoşuma gitti. En minimal, en hızlı imajı türetmek ve bunu tekrarlanabilir olarak bir text osyasında saklama fikri gerçektende müthiş. volumeler hakkında bir kaç şey öğrendim.

 - sql ise çok sıkıcı ve gereksiz verbose bir deneyim idi. sqlite3 ve file based database konseptinin, inanılmaz derecede çok kullanılan ve önemli bir kod parçası olduğunu öğrendim. Kodun çoğunun hand written assembly olması sqlite projesini, "muhteşem"den "kara büyü" ve "sanat" tarafına itmiş.

Son olarak, final product ibaresine en çok yaklaşan projem bu oldu. Bu projeyi ilerletmeyi veya benzeri farklı bir proje geliştirip internete salmaya çok heveslendim.
