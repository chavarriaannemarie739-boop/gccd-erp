/** 上传前媒体体积限制：200KB（与小程序常见单文件限制对齐） */
const MAX_MEDIA_BYTES = 200 * 1024;

const getFileSize = (filePath) =>
    new Promise((resolve, reject) => {
        wx.getFileInfo({
            filePath,
            success: (res) => resolve(res.size),
            fail: reject,
        });
    });

const compressImageWx = (src, options = {}) =>
    new Promise((resolve, reject) => {
        const payload = { src };
        if (options.quality != null) payload.quality = options.quality;
        if (options.compressedWidth != null) payload.compressedWidth = options.compressedWidth;
        if (options.compressedHeight != null) payload.compressedHeight = options.compressedHeight;
        wx.compressImage({
            ...payload,
            success: (res) => resolve(res.tempFilePath),
            fail: reject,
        });
    });

const isImageLike = (localPath, meta = {}) => {
    const p = String(localPath || '').toLowerCase();
    const name = String(meta.name || '').toLowerCase();
    const type = meta.type != null ? String(meta.type).toLowerCase() : '';
    if (type.startsWith('image')) return true;
    return /\.(jpg|jpeg|png|gif|webp)$/i.test(p) || /\.(jpg|jpeg|png|gif|webp)$/i.test(name);
};

const isAudioLike = (localPath, meta = {}) => {
    const p = String(localPath || '').toLowerCase();
    const name = String(meta.name || '').toLowerCase();
    const type = meta.type != null ? String(meta.type).toLowerCase() : '';
    if (type.startsWith('audio')) return true;
    return /\.(mp3|wav|m4a|aac|amr)$/i.test(p) || /\.(mp3|wav|m4a|aac|amr)$/i.test(name);
};

/**
 * 将本地图片压缩到不超过 MAX_MEDIA_BYTES；非图片仅做体积校验。
 * @param {string} localPath 本地临时路径
 * @param {{ type?: string, name?: string }} [meta] Vant uploader 等传入的 file 信息
 * @returns {Promise<string>} 可用于 wx.uploadFile 的 filePath
 */
const prepareLocalPathForUpload = async (localPath, meta = {}) => {
    if (!localPath) {
        throw new Error('无效的文件路径');
    }

    if (isImageLike(localPath, meta)) {
        let size = await getFileSize(localPath);
        if (size <= MAX_MEDIA_BYTES) return localPath;

        let current = localPath;
        const qualities = [80, 65, 50, 35, 25];
        for (let i = 0; i < qualities.length; i++) {
            try {
                current = await compressImageWx(current, { quality: qualities[i] });
                size = await getFileSize(current);
                if (size <= MAX_MEDIA_BYTES) return current;
            } catch (e) {
                break;
            }
        }

        const widths = [1600, 1200, 960, 720, 540];
        for (let w = 0; w < widths.length; w++) {
            try {
                let img = await compressImageWx(localPath, {
                    quality: 75,
                    compressedWidth: widths[w],
                });
                size = await getFileSize(img);
                if (size <= MAX_MEDIA_BYTES) return img;
                const q2 = [55, 40, 25];
                for (let j = 0; j < q2.length; j++) {
                    img = await compressImageWx(img, { quality: q2[j] });
                    size = await getFileSize(img);
                    if (size <= MAX_MEDIA_BYTES) return img;
                }
            } catch (e) {
                // 继续尝试更小尺寸
            }
        }

        throw new Error('图片无法压缩至200KB以内，请换一张较小的图片');
    }

    const size = await getFileSize(localPath);
    if (size > MAX_MEDIA_BYTES) {
        const label = isAudioLike(localPath, meta) ? '音频' : '文件';
        throw new Error(`${label}不能超过200KB`);
    }
    return localPath;
};

module.exports = {
    MAX_MEDIA_BYTES,
    prepareLocalPathForUpload,
};
