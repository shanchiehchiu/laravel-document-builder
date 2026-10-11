/*!
 * 20261006 WebFoundry 編輯器繁體中文語系（GrapesJS i18n messages）
 */
(function () {
    'use strict';

    var inputAttr = { placeholder: '請輸入' };

    window.WFBuilderI18n = {
        assetManager: {
            addButton: '加入網址',
            inputPlh: '貼上圖片網址 https://…',
            modalTitle: '選擇圖片',
            uploadTitle: '把圖片拖到這裡，或點一下選擇要上傳的檔案'
        },
        domComponents: {
            names: {
                '': '區塊',
                wrapper: '頁面',
                text: '文字',
                comment: '註解',
                image: '圖片',
                video: '影片',
                label: '欄位標題',
                link: '連結',
                map: '地圖',
                tfoot: '表尾',
                tbody: '表身',
                thead: '表頭',
                table: '表格',
                row: '表格列',
                cell: '儲存格',
                svg: '圖示',
                'svg-in': '圖示線條',
                textnode: '文字節點',
                script: '程式碼',
                iframe: '內嵌框架'
            }
        },
        deviceManager: {
            device: '裝置',
            devices: { desktop: '桌機', tablet: '平板', mobile: '手機' }
        },
        panels: {
            buttons: {
                titles: {
                    preview: '預覽',
                    fullscreen: '全螢幕',
                    'sw-visibility': '顯示框線',
                    'export-template': '檢視程式碼',
                    'open-sm': '樣式',
                    'open-tm': '設定',
                    'open-layers': '圖層',
                    'open-blocks': '元件'
                }
            }
        },
        selectorManager: {
            label: 'CSS 類別',
            selected: '目前套用',
            emptyState: '- 狀態 -',
            states: {
                hover: '滑鼠移過',
                active: '點擊中',
                'nth-of-type(2n)': '偶數項'
            }
        },
        styleManager: {
            empty: '請先在畫面上點選一個元件，再調整樣式',
            layer: '圖層',
            fileButton: '選擇圖片',
            // key 是 wf-builder.js styleManager.sectors 的 id（i18n 會蓋過 sector 的 name）
            sectors: {
                general: '一般',
                layout: '排列與位置',
                typography: '文字',
                decorations: '背景與外框',
                extra: '圖片與特效',
                flex: '彈性排列',
                dimension: '尺寸與間距'
            },
            properties: {
                display: '顯示方式',
                float: '浮動',
                position: '定位',
                top: '上',
                right: '右',
                left: '左',
                bottom: '下',
                'z-index': '圖層順序',
                width: '寬度',
                'min-width': '最小寬度',
                'max-width': '最大寬度',
                height: '高度',
                'min-height': '最小高度',
                'max-height': '最大高度',
                margin: '外距',
                padding: '內距',
                'margin-top-sub': '上',
                'margin-right-sub': '右',
                'margin-bottom-sub': '下',
                'margin-left-sub': '左',
                'padding-top-sub': '上',
                'padding-right-sub': '右',
                'padding-bottom-sub': '下',
                'padding-left-sub': '左',
                'font-family': '字型',
                'font-size': '文字大小',
                'font-weight': '粗細',
                'font-style': '斜體',
                'letter-spacing': '字距',
                color: '文字顏色',
                'line-height': '行高',
                'text-align': '對齊',
                'text-decoration': '裝飾線',
                'text-transform': '大小寫',
                'text-shadow': '文字陰影',
                'text-shadow-h': 'X',
                'text-shadow-v': 'Y',
                'text-shadow-blur': '模糊',
                'text-shadow-color': '顏色',
                'background-color': '背景顏色',
                background: '背景圖片',
                'background-image-sub': '圖片',
                'background-repeat-sub': '重複',
                'background-position-sub': '位置',
                'background-attachment-sub': '捲動',
                'background-size-sub': '尺寸',
                'border-radius': '圓角',
                'border-radius-c': '圓角',
                'border-top-left-radius-sub': '左上',
                'border-top-right-radius-sub': '右上',
                'border-bottom-right-radius-sub': '右下',
                'border-bottom-left-radius-sub': '左下',
                border: '框線',
                'border-width-sub': '粗細',
                'border-style-sub': '樣式',
                'border-color-sub': '顏色',
                'box-shadow': '陰影',
                'box-shadow-h': 'X',
                'box-shadow-v': 'Y',
                'box-shadow-blur': '模糊',
                'box-shadow-spread': '擴散',
                'box-shadow-color': '顏色',
                'box-shadow-type': '類型',
                opacity: '透明度',
                overflow: '超出範圍',
                cursor: '游標',
                transition: '轉場動畫',
                'transition-property-sub': '屬性',
                'transition-duration-sub': '時間',
                'transition-timing-function-sub': '速度曲線',
                transform: '變形',
                'flex-direction': '排列方向',
                'flex-wrap': '換行',
                'justify-content': '主軸對齊',
                'align-items': '交叉軸對齊',
                'align-self': '自身對齊',
                gap: '間距',
                'flex-grow': '放大比例',
                'flex-shrink': '縮小比例',
                'flex-basis': '基準寬度',
                order: '順序',
                'object-fit': '圖片填滿',
                'aspect-ratio': '長寬比'
            },
            options: {
                display: { block: '區塊', 'inline-block': '行內區塊', inline: '行內', flex: '彈性排列 (flex)', grid: '格線 (grid)', none: '隱藏' },
                position: { static: '預設', relative: '相對', absolute: '絕對', fixed: '固定', sticky: '黏貼' },
                float: { none: '無', left: '靠左', right: '靠右' },
                'font-weight': { 100: '極細', 200: '特細', 300: '細', 400: '一般', 500: '中等', 600: '半粗', 700: '粗', 800: '特粗', 900: '極粗' },
                'flex-direction': { row: '橫向', 'row-reverse': '橫向（反向）', column: '直向', 'column-reverse': '直向（反向）' },
                'flex-wrap': { nowrap: '不換行', wrap: '換行', 'wrap-reverse': '反向換行' },
                overflow: { visible: '顯示', hidden: '隱藏', scroll: '捲動', auto: '自動' }
            }
        },
        traitManager: {
            empty: '請先在畫面上點選一個元件',
            label: '元件設定',
            categories: {},
            traits: {
                labels: {
                    id: 'ID',
                    alt: '替代文字',
                    title: '提示文字',
                    href: '連結網址',
                    target: '開啟方式',
                    name: '欄位名稱',
                    placeholder: '提示文字',
                    required: '必填',
                    type: '類型',
                    value: '值',
                    provider: '影片來源',
                    videoId: '影片 ID',
                    src: '來源網址',
                    autoplay: '自動播放',
                    controls: '顯示控制列',
                    loop: '重複播放',
                    rel: '顯示相關影片',
                    modestbranding: '簡化標誌',
                    poster: '預覽圖',
                    color: '顏色',
                    address: '地址',
                    mapType: '地圖類型',
                    zoom: '縮放'
                },
                attributes: {
                    id: inputAttr,
                    alt: { placeholder: '描述圖片內容（SEO 與無障礙）' },
                    title: inputAttr,
                    href: { placeholder: '例：https://… 或 /site/…' }
                },
                options: {
                    target: { false: '目前視窗', _blank: '另開新視窗' },
                    provider: { so: '自訂影片檔', yt: 'YouTube', ytnc: 'YouTube（不追蹤）', vi: 'Vimeo' },
                    mapType: { q: '一般地圖', w: '衛星地圖' }
                }
            }
        },
        storageManager: {
            recover: '有尚未儲存的修改，要復原嗎？'
        }
    };
})();
