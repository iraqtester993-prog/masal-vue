package iq.dananir.pos;

import android.content.Context;
import android.graphics.BitmapFactory;
import android.net.Uri;
import androidx.core.content.FileProvider;
import java.io.*;
import java.util.UUID;

/** Copy a bounded, validated image into an app-owned read-only upload location. */
final class SafeImageUpload {
    static final int MAX_BYTES=700000;
    static Uri prepare(Context context,Uri source) throws Exception {
        if(source==null||!"content".equals(source.getScheme()))throw new IOException("اختر صورة من ملفات الجهاز.");
        byte[] bytes;
        try(InputStream input=context.getContentResolver().openInputStream(source);ByteArrayOutputStream output=new ByteArrayOutputStream()) {
            if(input==null)throw new IOException("تعذر قراءة الصورة.");
            byte[] chunk=new byte[8192];int count;
            while((count=input.read(chunk))!=-1){if(output.size()+count>MAX_BYTES)throw new IOException("حجم الصورة يتجاوز الحد المسموح.");output.write(chunk,0,count);}
            bytes=output.toByteArray();
        }
        BitmapFactory.Options bounds=new BitmapFactory.Options();bounds.inJustDecodeBounds=true;BitmapFactory.decodeByteArray(bytes,0,bytes.length,bounds);
        if(bounds.outWidth<1||bounds.outHeight<1||bounds.outWidth>4096||bounds.outHeight>4096||!("image/png".equals(bounds.outMimeType)||"image/jpeg".equals(bounds.outMimeType)))throw new IOException("اختر صورة PNG أو JPEG بقياس لا يتجاوز 4096 بكسل.");
        File directory=new File(context.getCacheDir(),"image-uploads");if(!directory.isDirectory()&&!directory.mkdirs())throw new IOException("تعذر تجهيز الصورة.");
        File[] previous=directory.listFiles();if(previous!=null)for(File file:previous)if(file.lastModified()<System.currentTimeMillis()-86400000L)file.delete();
        File copy=new File(directory,UUID.randomUUID()+ ("image/png".equals(bounds.outMimeType)?".png":".jpg"));
        try(FileOutputStream output=new FileOutputStream(copy)){output.write(bytes);}
        return FileProvider.getUriForFile(context,context.getPackageName()+".uploads",copy);
    }
    static void clear(Context context){File[] files=new File(context.getCacheDir(),"image-uploads").listFiles();if(files!=null)for(File file:files)file.delete();}
}
